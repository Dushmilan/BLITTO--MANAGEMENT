# L5 Cross-Cutting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the platform's cross-cutting concerns real — notifications leave the app by mail, every mutation is audited with an IP, the portfolio is reportable, stalled NIPO filings chase themselves, and the reference demo dataset can be seeded.

**Architecture:** A `MailChannel` port (`laravel` production adapter over Laravel's mailer, `array` test adapter) owns delivery; `NotificationService` persists the row first and delivers best-effort. Audit gaps are closed at the existing single entry points (`UserDirectory::register()`, the auth controllers, the filing steps' IP). L4's grant-without-document logic moves into one `GrantWarning` shared by both grant paths. Analytics and the NIPO reminder are new plain domain modules behind thin controllers/commands; the seeder restores the reference snapshot idempotently, writing document bytes through the existing `DocumentStore` port.

**Tech Stack:** PHP 8.3, Laravel 13.35, Pest 3, SQLite, Blade (one mail view).

**Spec:** `docs/superpowers/specs/2026-10-18-l5-cross-cutting-design.md`

## Global Constraints

- No new Composer dependency. **No schema change** — no migrations, no new columns.
- Business rules live in `backend/app/Domain/*`; controllers and console commands stay thin; Eloquent models stay persistence-only.
- Every seam has a production adapter and a test adapter.
- Config keys use the `BLITTODB_` prefix with the default in `backend/config/blitto.php`; `.env.example` lists them **commented out**.
- Notification templates are byte-identical to the reference, em-dashes included.
- Tests are Pest (`it('...', function () {...})`), `RefreshDatabase` comes from `backend/tests/Pest.php`. Never delete or rewrite an existing test assertion without a ruling; adding to a file is fine.
- Run tests as `cd backend && php artisan test --compact <path>`; after PHP changes run `cd backend && vendor/bin/pint --dirty --format agent`.
- Commit after every task, Conventional Commits style (`feat(l5): ...`, `test(l5): ...`, `docs(l5): ...`).
- The L4 code is the base: `DocumentService::hasDocuments(Application): bool`, `DocumentStore`/`LocalDocumentStore`, `NotificationService::send($recipientEmail, $subject, $body, $kind = 'status_change')`.

## Review Focus

1. `by_status`/`by_type` must serialize as JSON **objects** even when empty; `[]` breaks any client reading them as maps. (`{}` is pinned in Task 10.)
2. A stalled filing whose `filed_date` is null must still remind staff rather than crash the reminder. (Task 11.)
3. A snapshot missing an optional section (`docketing`, `notification`, `audit`, `document_vault`) must import cleanly instead of dying on an undefined key. (Task 13.)
4. A notification addressed to a blank/absent recipient must not fail the action that produced it. (Task 6.)
5. A seeded document whose base64 content is missing or corrupt must be skipped loudly — never a `documents` row without bytes (the L4 lesson). (Task 15.)

---

### Task 1: Filing audit rows carry the client IP

**Files:**
- Modify: `backend/app/Domain/Filing/FilingWorkflowService.php`
- Modify: `backend/app/Http/Controllers/FilingController.php`
- Test: `backend/tests/Feature/FilingAuditTest.php` (create)

**Interfaces:**
- Consumes: `AuditRecorder::record(string $action, ?User $actor = null, ?Application $application = null, ?string $ipAddress = null)`.
- Produces: `FilingWorkflowService::markFiled(Application $application, User $actor, ?string $ipAddress = null): Application`, and the same trailing optional `?string $ipAddress = null` on `acknowledgeNipo`, `markGranted`, `recordDefectSheet`, `markRejected`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\User;

it('records the client IP on the file, acknowledge and grant audit rows', function () {
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Filed', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/file")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/acknowledge")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/grant?patent_number=LK/PAT/2026/9")->assertOk();

    $rows = AuditLog::query()
        ->whereIn('action', ['filing:mark_filed', 'filing:acknowledge_nipo', 'filing:granted'])
        ->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->whereNull('ip_address'))->toHaveCount(0)
        ->and($rows->pluck('ip_address')->unique()->all())->toBe(['127.0.0.1']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/FilingAuditTest.php`
Expected: FAIL — the three rows have `ip_address` null, so `toHaveCount(0)` fails.

- [ ] **Step 3: Implement the IP pass-through for those three steps**

In `FilingWorkflowService`, add the trailing parameter to each signature and pass it to the existing audit call, e.g.

```php
public function markFiled(Application $application, User $actor, ?string $ipAddress = null): Application
{
    // ... unchanged body ...
    $this->audit->record('filing:mark_filed', actor: $actor, application: $application, ipAddress: $ipAddress);
    // ... unchanged remainder ...
}
```

Do the same for `acknowledgeNipo` (`filing:acknowledge_nipo`) and `markGranted` (`filing:granted`).

In `FilingController`, pass the request IP in the three corresponding methods:

```php
return response()->json($this->filing->markFiled(
    $this->intake->findOrFail($applicationId),
    $request->user(),
    $request->ip(),
));
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact tests/Feature/FilingAuditTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Write the failing test for the defect-sheet and reject rows**

```php
it('records the client IP on the defect-sheet and reject audit rows', function () {
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);

    $defective = Application::query()->create(['title' => 'Defective', 'inventor_email' => 'ada@pdn.ac.lk']);
    $rejected = Application::query()->create(['title' => 'Rejected', 'inventor_email' => 'bob@pdn.ac.lk']);

    $this->actingAs($md)->postJson("/admin/filing/{$defective->id}/file")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$defective->id}/defect-sheets", [
        'sheet_number' => 1,
        'description' => 'Claim 1 lacks novelty',
    ])->assertOk();

    $this->actingAs($md)->postJson("/admin/filing/{$rejected->id}/file")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$rejected->id}/reject")->assertOk();

    $rows = AuditLog::query()->whereIn('action', ['filing:defect_sheet', 'filing:rejected'])->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->whereNull('ip_address'))->toHaveCount(0)
        ->and($rows->pluck('ip_address')->unique()->all())->toBe(['127.0.0.1']);
});
```

- [ ] **Step 6: Run it to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/FilingAuditTest.php`
Expected: FAIL on the new test (2 rows with a null IP).

- [ ] **Step 7: Implement the remaining two steps**

Add the trailing `?string $ipAddress = null` to `recordDefectSheet` (`filing:defect_sheet`) and `markRejected` (`filing:rejected`), pass it to their audit calls, and pass `$request->ip()` from `FilingController::storeDefectSheet` and `FilingController::reject`.

- [ ] **Step 8: Run the filing tests and Pint**

Run: `cd backend && php artisan test --compact tests/Feature/FilingAuditTest.php tests/Feature/FilingWorkflowTest.php tests/Feature/FilingEndpointTest.php tests/Feature/GrantWithoutDocumentTest.php && vendor/bin/pint --dirty --format agent`
Expected: all PASS, Pint clean.

- [ ] **Step 9: Commit**

```bash
git add backend/app/Domain/Filing/FilingWorkflowService.php backend/app/Http/Controllers/FilingController.php backend/tests/Feature/FilingAuditTest.php
git commit -m "feat(l5): record the client IP on every filing audit row"
```

---

### Task 2: User creation records `create_user`

**Files:**
- Modify: `backend/app/Domain/Authorization/UserDirectory.php`
- Modify: `backend/app/Http/Controllers/Auth/RegisterController.php`
- Test: `backend/tests/Feature/UserCreationAuditTest.php` (create)

**Interfaces:**
- Produces: `UserDirectory::register(string $email, Role $role, ?string $password = null, ?string $userCode = null, ?User $actor = null, ?string $ipAddress = null): User`, which additionally writes an `AuditRecorder` row with action `create_user`.
- Consumes: `AuditRecorder` (Task 1 does not change it).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Domain\Authorization\BootstrapAdmin;
use App\Models\AuditLog;
use App\Models\User;

it('records create_user when an MD registers a director', function () {
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);

    $this->actingAs($md)->postJson('/auth/register', [
        'email' => 'new.director@pdn.ac.lk',
        'role' => 'director',
        'password' => 'secret-password',
    ])->assertOk();

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('create_user')
        ->and($log->user_id)->toBe($md->id)
        ->and($log->application_id)->toBeNull()
        ->and($log->ip_address)->toBe('127.0.0.1');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/UserCreationAuditTest.php`
Expected: FAIL — `AuditLog::query()->sole()` throws (no rows).

- [ ] **Step 3: Implement**

In `UserDirectory`:

```php
public function __construct(private readonly AuditRecorder $audit) {}

public function register(
    string $email,
    Role $role,
    ?string $password = null,
    ?string $userCode = null,
    ?User $actor = null,
    ?string $ipAddress = null,
): User {
    // ... unchanged validation, hashing and User::query()->create(...) ...
    $this->audit->record('create_user', actor: $actor, ipAddress: $ipAddress);

    return $user;
}
```

Store the created model in a `$user` variable first so the audit row is written after a successful create. Import `App\Domain\Audit\AuditRecorder`.

In `RegisterController::store`, pass the acting user and IP through (named arguments, matching the existing call style):

```php
$user = $this->directory->register(
    email: (string) $request->input('email', ''),
    role: $role,
    password: $request->filled('password') ? (string) $request->input('password') : null,
    userCode: $request->filled('user_code') ? (string) $request->input('user_code') : null,
    actor: $actor,
    ipAddress: $request->ip(),
);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact tests/Feature/UserCreationAuditTest.php`
Expected: PASS.

- [ ] **Step 5: Add the bootstrap-admin test (characterization, not a new RED)**

```php
it('records a create_user row for the bootstrap admin with no actor', function () {
    config([
        'blitto.bootstrap_admin_email' => 'root@pdn.ac.lk',
        'blitto.bootstrap_admin_password' => 'bootstrap-password',
    ]);

    app(BootstrapAdmin::class)->ensure();

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('create_user')
        ->and($log->user_id)->toBeNull()
        ->and($log->ip_address)->toBeNull();
});
```

- [ ] **Step 6: Run it (it passes immediately, and that is the point)**

Run: `cd backend && php artisan test --compact tests/Feature/UserCreationAuditTest.php`
Expected: PASS on the first run — this one cannot go red, because `BootstrapAdmin` reaches the same `UserDirectory::register()` that Step 3 already instrumented. That is exactly the invariant being pinned: no creation path can bypass the audit. Say so in the commit message rather than hunting for a fake RED.

- [ ] **Step 7: Run the surrounding suites and Pint**

Run: `cd backend && php artisan test --compact tests/Feature/AuthRegisterTest.php tests/Feature/UserManagementTest.php tests/Feature/BootstrapAdminTest.php tests/Feature/UserCreationAuditTest.php && vendor/bin/pint --dirty --format agent`
Expected: all PASS, Pint clean. If `BootstrapAdminTest` or `UserManagementTest` counts audit rows, report the failure instead of editing those assertions.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Domain/Authorization/UserDirectory.php backend/app/Http/Controllers/Auth/RegisterController.php backend/tests/Feature/UserCreationAuditTest.php
git commit -m "feat(l5): audit user creation at the single creation entry point"
```

---

### Task 3: Login and logout record `auth:login` / `auth:logout`

**Files:**
- Modify: `backend/app/Http/Controllers/Auth/LoginController.php`
- Modify: `backend/app/Http/Controllers/Auth/LogoutController.php`
- Test: `backend/tests/Feature/AuthAuditTest.php` (create)

**Interfaces:**
- Consumes: `AuditRecorder` (unchanged).
- Produces: audit rows `auth:login` and `auth:logout` with the authenticated user and request IP.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\AuditLog;
use App\Models\User;

it('records auth:login and auth:logout with the actor and the ip', function () {
    $user = User::factory()->withPassword('s3cret-pw')->create(['email' => 'ada@pdn.ac.lk']);

    $this->postJson('/auth/login', ['email' => 'ada@pdn.ac.lk', 'password' => 's3cret-pw'])->assertOk();
    $this->postJson('/auth/logout')->assertNoContent();

    $rows = AuditLog::query()->orderBy('timestamp')->get();

    expect($rows->pluck('action')->all())->toBe(['auth:login', 'auth:logout'])
        ->and($rows->pluck('user_id')->unique()->all())->toBe([$user->id])
        ->and($rows->pluck('ip_address')->unique()->all())->toBe(['127.0.0.1']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/AuthAuditTest.php`
Expected: FAIL — no audit rows.

- [ ] **Step 3: Implement in both controllers**

`LoginController` — add the collaborator and record after a successful attempt, before returning:

```php
public function __construct(
    private readonly AttemptThrottle $throttle,
    private readonly AuditRecorder $audit,
) {}

// after $this->throttle->clear(...) and $request->session()->regenerate():
$this->audit->record('auth:login', actor: Auth::user(), ipAddress: $request->ip());
```

`LogoutController` — capture the user before the session is destroyed:

```php
public function __construct(private readonly AuditRecorder $audit) {}

public function store(Request $request): JsonResponse
{
    $actor = $request->user();

    Auth::guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    $this->audit->record('auth:logout', actor: $actor, ipAddress: $request->ip());

    return response()->json(null, 204);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact tests/Feature/AuthAuditTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing test for a failed login**

```php
it('writes no audit row for a failed login attempt', function () {
    User::factory()->withPassword('s3cret-pw')->create(['email' => 'ada@pdn.ac.lk']);

    $this->postJson('/auth/login', ['email' => 'ada@pdn.ac.lk', 'password' => 'wrong'])
        ->assertUnauthorized();

    expect(AuditLog::query()->count())->toBe(0);
});
```

- [ ] **Step 6: Run it to verify it passes without further code**

Run: `cd backend && php artisan test --compact tests/Feature/AuthAuditTest.php`
Expected: PASS — the record call sits after the successful branch only. If it FAILS, fix the placement rather than the test.

- [ ] **Step 7: Run the auth suites and Pint**

Run: `cd backend && php artisan test --compact tests/Feature/AuthLoginTest.php tests/Feature/AuthLogoutTest.php tests/Feature/AuthSessionTest.php tests/Feature/LoginThrottleTest.php tests/Feature/AuthAuditTest.php && vendor/bin/pint --dirty --format agent`
Expected: all PASS, Pint clean.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Http/Controllers/Auth/LoginController.php backend/app/Http/Controllers/Auth/LogoutController.php backend/tests/Feature/AuthAuditTest.php
git commit -m "feat(l5): audit successful logins and logouts"
```

---

### Task 4: The `MailChannel` port, the array adapter, and the binding

**Files:**
- Create: `backend/app/Domain/Notification/MailChannel.php`
- Create: `backend/app/Domain/Notification/ArrayMailChannel.php`
- Create: `backend/app/Domain/Notification/UnsupportedMailChannel.php`
- Modify: `backend/config/blitto.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Modify: `backend/phpunit.xml`
- Modify: `backend/.env.example`
- Test: `backend/tests/Feature/MailChannelTest.php` (create)
- Test: `backend/tests/Feature/BlittoConfigTest.php` (add)

**Interfaces:**
- Produces: `MailChannel::send(string $to, string $subject, string $body): void`; `ArrayMailChannel::messages(): array<int, array{to: string, subject: string, body: string}>`, `ArrayMailChannel::sentTo(string $email): array`, `ArrayMailChannel::lastMessage(): ?array`; config key `blitto.mail_channel` (`laravel` default, `array` in tests); container binding `MailChannel::class` as a singleton.
- Consumes: nothing from earlier tasks.

- [ ] **Step 1: Write the failing config test**

Add to `backend/tests/Feature/BlittoConfigTest.php` (append the assertion to the existing "parity defaults" test and add the env-override test):

```php
it('defaults the mail channel to laravel and reads the env override', function () {
    expect(config('blitto.mail_channel'))->toBe('laravel');

    putenv('BLITTODB_MAIL_CHANNEL=array');
    $_ENV['BLITTODB_MAIL_CHANNEL'] = 'array';
    $this->refreshApplication();

    expect(config('blitto.mail_channel'))->toBe('array');

    putenv('BLITTODB_MAIL_CHANNEL');
    unset($_ENV['BLITTODB_MAIL_CHANNEL']);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/BlittoConfigTest.php`
Expected: FAIL — `config('blitto.mail_channel')` is null.

- [ ] **Step 3: Add the config key**

In `backend/config/blitto.php`, after the `document_store` entry:

```php
    // Mail delivery adapter: "laravel" delegates to Laravel's mailer (MAIL_MAILER
    // picks the transport: log locally, smtp in production); "array" keeps
    // messages in memory for tests.
    'mail_channel' => env('BLITTODB_MAIL_CHANNEL', 'laravel'),
```

- [ ] **Step 4: Run it to verify it passes**

Run: `cd backend && php artisan test --compact tests/Feature/BlittoConfigTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing binding test**

```php
<?php

use App\Domain\Notification\ArrayMailChannel;
use App\Domain\Notification\MailChannel;
use App\Domain\Notification\UnsupportedMailChannel;

it('resolves the array adapter from the mail_channel config', function () {
    config(['blitto.mail_channel' => 'array']);
    app()->forgetInstance(MailChannel::class);

    $channel = app(MailChannel::class);

    expect($channel)->toBeInstanceOf(ArrayMailChannel::class);

    $channel->send('ada@pdn.ac.lk', 'Subject', 'Body');

    expect($channel->messages())->toBe([
        ['to' => 'ada@pdn.ac.lk', 'subject' => 'Subject', 'body' => 'Body'],
    ])->and($channel->sentTo('ada@pdn.ac.lk'))->toHaveCount(1)
        ->and($channel->sentTo('bob@pdn.ac.lk'))->toHaveCount(0)
        ->and($channel->lastMessage())->toBe(['to' => 'ada@pdn.ac.lk', 'subject' => 'Subject', 'body' => 'Body']);
});

it('fails loudly for an unknown mail channel', function () {
    config(['blitto.mail_channel' => 'carrier-pigeon']);
    app()->forgetInstance(MailChannel::class);

    expect(fn () => app(MailChannel::class))->toThrow(UnsupportedMailChannel::class);
});
```

- [ ] **Step 6: Run it to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/MailChannelTest.php`
Expected: FAIL — `MailChannel` does not exist.

- [ ] **Step 7: Implement the port, the array adapter, the exception and the binding**

`backend/app/Domain/Notification/MailChannel.php`:

```php
<?php

namespace App\Domain\Notification;

/**
 * Outbound mail seam. Mirrors the reference `EmailProviderAdapter.send`.
 * Implementations may throw; NotificationService decides what a failure means.
 */
interface MailChannel
{
    public function send(string $to, string $subject, string $body): void;
}
```

`backend/app/Domain/Notification/ArrayMailChannel.php`:

```php
<?php

namespace App\Domain\Notification;

/**
 * Test adapter: keeps every message in memory so tests can assert delivery
 * through our own seam instead of the framework's mail fake.
 */
final class ArrayMailChannel implements MailChannel
{
    /** @var list<array{to: string, subject: string, body: string}> */
    private array $messages = [];

    public function send(string $to, string $subject, string $body): void
    {
        $this->messages[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }

    /** @return list<array{to: string, subject: string, body: string}> */
    public function messages(): array
    {
        return $this->messages;
    }

    /** @return list<array{to: string, subject: string, body: string}> */
    public function sentTo(string $email): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (array $message): bool => $message['to'] === $email,
        ));
    }

    /** @return array{to: string, subject: string, body: string}|null */
    public function lastMessage(): ?array
    {
        return $this->messages === [] ? null : $this->messages[array_key_last($this->messages)];
    }
}
```

`backend/app/Domain/Notification/UnsupportedMailChannel.php`:

```php
<?php

namespace App\Domain\Notification;

use RuntimeException;

/** Raised when BLITTODB_MAIL_CHANNEL names an adapter that is not implemented. */
final class UnsupportedMailChannel extends RuntimeException {}
```

In `AppServiceProvider::register()`, add after the `DocumentStore` binding:

```php
$this->app->singleton(MailChannel::class, static function (): MailChannel {
    $configured = (string) config('blitto.mail_channel');

    return match ($configured) {
        'array' => new ArrayMailChannel,
        // Task 5 inserts 'laravel' => new LaravelMailChannel above this default.
        default => throw new UnsupportedMailChannel(
            "Mail channel [{$configured}] is not available; use \"laravel\" or \"array\".",
        ),
    };
});
```

Only the `array` arm exists in this task, which is what the two tests exercise: the test env resolves `array`, and an unknown value hits the loud `default`. Task 5 adds the `laravel` arm the milestone needs; nothing in this task resolves the default `laravel` config outside the testing environment.

- [ ] **Step 8: Set the test env and document the key**

`backend/phpunit.xml`, inside `<php>`, next to `MAIL_MAILER`:

```xml
        <env name="BLITTODB_MAIL_CHANNEL" value="array"/>
```

`backend/.env.example` — append to the commented `BLITTODB_*` block:

```
# BLITTODB_MAIL_CHANNEL=laravel
```

- [ ] **Step 9: Run the tests**

Run: `cd backend && php artisan test --compact tests/Feature/MailChannelTest.php tests/Feature/BlittoConfigTest.php`
Expected: PASS (4 tests).

- [ ] **Step 10: Commit**

```bash
git add backend/app/Domain/Notification backend/config/blitto.php backend/app/Providers/AppServiceProvider.php backend/phpunit.xml backend/.env.example backend/tests/Feature/MailChannelTest.php backend/tests/Feature/BlittoConfigTest.php
git commit -m "feat(l5): add the mail channel port with an in-memory test adapter"
```

---

### Task 5: The Laravel-backed adapter and the Mailable

**Files:**
- Create: `backend/app/Domain/Notification/LaravelMailChannel.php`
- Create: `backend/app/Mail/NotificationMail.php`
- Create: `backend/resources/views/mail/notification.blade.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Test: `backend/tests/Feature/LaravelMailChannelTest.php` (create)

**Interfaces:**
- Consumes: `MailChannel` (Task 4).
- Produces: `LaravelMailChannel implements MailChannel`; `App\Mail\NotificationMail` with public `string $subjectLine` and `string $bodyText`; the `laravel` branch of the `MailChannel` binding.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Domain\Notification\LaravelMailChannel;
use App\Domain\Notification\MailChannel;
use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;

it('dispatches a NotificationMail through laravels mail layer', function () {
    Mail::fake();

    (new LaravelMailChannel)->send('ada@pdn.ac.lk', 'Patent filed', "Line one.\nLine two.");

    Mail::assertSent(NotificationMail::class, function (NotificationMail $mail): bool {
        return $mail->hasTo('ada@pdn.ac.lk')
            && $mail->subjectLine === 'Patent filed'
            && $mail->bodyText === "Line one.\nLine two."
            && str_contains($mail->render(), 'Line one.')
            && str_contains($mail->render(), 'Line two.');
    });
});

it('binds the laravel adapter from the mail_channel config', function () {
    config(['blitto.mail_channel' => 'laravel']);
    app()->forgetInstance(MailChannel::class);

    expect(app(MailChannel::class))->toBeInstanceOf(LaravelMailChannel::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/LaravelMailChannelTest.php`
Expected: FAIL — `LaravelMailChannel` does not exist.

- [ ] **Step 3: Implement the Mailable, the view and the adapter**

`backend/app/Mail/NotificationMail.php`:

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The single mailable for every persisted notification: subject and body are
 * already composed by NotificationService, so there is no per-kind class.
 */
final class NotificationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.notification');
    }
}
```

`backend/resources/views/mail/notification.blade.php`:

```blade
<p>{!! nl2br(e($bodyText)) !!}</p>
```

`backend/app/Domain/Notification/LaravelMailChannel.php`:

```php
<?php

namespace App\Domain\Notification;

use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;

/**
 * Production adapter: hands the message to Laravel's mailer, so the transport
 * (log, smtp, array) stays a MAIL_MAILER configuration concern.
 */
final class LaravelMailChannel implements MailChannel
{
    public function send(string $to, string $subject, string $body): void
    {
        Mail::to($to)->send(new NotificationMail($subject, $body));
    }
}
```

In `AppServiceProvider::register()`, replace the `laravel` fall-through from Task 4:

```php
        'laravel' => new LaravelMailChannel,
        'array' => new ArrayMailChannel,
        default => throw new UnsupportedMailChannel(...),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact tests/Feature/LaravelMailChannelTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Set a real sender address**

`backend/.env.example`: change `MAIL_FROM_ADDRESS="hello@example.com"` to `MAIL_FROM_ADDRESS="no-reply@pdn.ac.lk"`.

- [ ] **Step 6: Run the mail tests and Pint**

Run: `cd backend && php artisan test --compact tests/Feature/LaravelMailChannelTest.php tests/Feature/MailChannelTest.php && vendor/bin/pint --dirty --format agent`
Expected: all PASS, Pint clean.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Domain/Notification/LaravelMailChannel.php backend/app/Mail backend/resources/views/mail backend/app/Providers/AppServiceProvider.php backend/.env.example backend/tests/Feature/LaravelMailChannelTest.php
git commit -m "feat(l5): deliver notifications through laravels mail layer"
```

---

### Task 6: `NotificationService` persists first, then delivers best-effort

**Files:**
- Modify: `backend/app/Domain/Notification/NotificationService.php`
- Modify: `backend/tests/Feature/NotificationServiceTest.php` (the four `new NotificationService` call sites)
- Test: `backend/tests/Feature/NotificationDeliveryTest.php` (create)

**Interfaces:**
- Consumes: `MailChannel` (Tasks 4-5).
- Produces: `NotificationService::__construct(MailChannel $channel)`; `send()` still returns `Notification` and never throws on a delivery failure.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Domain\Notification\MailChannel;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

it('persists the notification and delivers it through the mail channel', function () {
    $channel = app(MailChannel::class);

    app(NotificationService::class)->send('ada@pdn.ac.lk', 'Patent filed', 'Body text');

    $notification = Notification::query()->sole();

    expect($notification->recipient_email)->toBe('ada@pdn.ac.lk')
        ->and($notification->kind)->toBe('status_change')
        ->and($channel->messages())->toBe([[
            'to' => 'ada@pdn.ac.lk',
            'subject' => 'Patent filed',
            'body' => 'Body text',
        ]]);
});

it('keeps the row and lets the action succeed when delivery fails', function () {
    Log::spy();

    $this->app->instance(MailChannel::class, new class implements MailChannel
    {
        public function send(string $to, string $subject, string $body): void
        {
            throw new RuntimeException('smtp is on fire');
        }
    });

    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Notified', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($md)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'FILED',
        'reason' => 'Filed receipt recorded',
    ])->assertNoContent();

    expect(Notification::query()->count())->toBe(1)
        ->and(Notification::query()->sole()->recipient_email)->toBe('ada@pdn.ac.lk');

    Log::shouldHaveReceived('warning')->once();
});

it('records a notification with a blank recipient instead of failing', function () {
    app(NotificationService::class)->send('', 'Subject', 'Body');

    expect(Notification::query()->sole()->recipient_email)->toBe('');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/NotificationDeliveryTest.php`
Expected: FAIL — `NotificationService` takes no constructor argument, so no message reaches the channel.

- [ ] **Step 3: Implement**

```php
public function __construct(private readonly MailChannel $channel) {}

public function send(string $recipientEmail, string $subject, string $body, string $kind = 'status_change'): Notification
{
    $notification = Notification::query()->create([
        'recipient_email' => $recipientEmail,
        'subject' => $subject,
        'body' => $body,
        'kind' => $kind,
    ]);

    try {
        $this->channel->send($recipientEmail, $subject, $body);
    } catch (Throwable $exception) {
        Log::warning('Notification mail delivery failed', [
            'notification_id' => $notification->id,
            'recipient_email' => $recipientEmail,
            'kind' => $kind,
            'error' => $exception->getMessage(),
        ]);
    }

    return $notification;
}
```

Imports: `App\Domain\Notification\MailChannel` is same-namespace, `Illuminate\Support\Facades\Log`, `Throwable`.

- [ ] **Step 4: Update the four direct instantiations in the existing test**

`backend/tests/Feature/NotificationServiceTest.php` calls `(new NotificationService)` in four places. Change each to `(new NotificationService(new ArrayMailChannel))` and import `App\Domain\Notification\ArrayMailChannel`. Do not change any assertion.

- [ ] **Step 5: Run the tests**

Run: `cd backend && php artisan test --compact tests/Feature/NotificationDeliveryTest.php tests/Feature/NotificationServiceTest.php tests/Feature/StatusChangeTest.php tests/Feature/FilingWorkflowTest.php tests/Feature/GrantWithoutDocumentTest.php`
Expected: all PASS. If a suite that counts notifications fails, report it — do not edit those assertions.

- [ ] **Step 6: Run the whole suite and Pint**

Run: `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact`
Expected: the baseline 350 tests plus every test added so far, all PASS, 0 failures. Pay attention to any suite that counts `notifications` or `audit_logs` rows — if one moved, report it rather than adjusting the assertion.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Domain/Notification/NotificationService.php backend/tests
git commit -m "feat(l5): persist notifications first and deliver mail best-effort"
```

---

### Task 7: Extract `GrantWarning` and audit the filing path's warning

**Files:**
- Create: `backend/app/Domain/Vault/GrantWarning.php`
- Modify: `backend/app/Domain/Filing/FilingWorkflowService.php`
- Test: `backend/tests/Feature/GrantWithoutDocumentTest.php` (add one test)

**Interfaces:**
- Consumes: `DocumentService::hasDocuments(Application): bool`, `NotificationService::send(...)`, `AuditRecorder::record(...)`.
- Produces: `GrantWarning::hasDocument(Application $application): bool`, `GrantWarning::warning(Application $application): ?string`, `GrantWarning::announce(Application $application, User $actor, ?string $ipAddress = null): ?string`. `FilingWorkflowService::grantWarning(Application): ?string` keeps its name and behaviour (the controller's `warning` JSON field).

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Feature/GrantWithoutDocumentTest.php`:

```php
it('audits grant_without_document when the filing path grants without a document', function () {
    Storage::fake('vault');
    config(['blitto.bootstrap_admin_email' => 'admin@pdn.ac.lk']);

    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Bare grant', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/file")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/acknowledge")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/grant?patent_number=LK/PAT/2026/11")->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'grant_without_document',
        'application_id' => $application->id,
        'user_id' => $md->id,
        'ip_address' => '127.0.0.1',
    ]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/GrantWithoutDocumentTest.php`
Expected: FAIL — no `grant_without_document` audit row is written.

- [ ] **Step 3: Implement `GrantWarning`**

```php
<?php

namespace App\Domain\Vault;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\User;

/**
 * The grant-without-document obligation, shared by both paths that can grant a
 * patent (the filing workflow and a generic status change): warn the inventor
 * that the document is pending, warn the admin inbox, and audit it.
 */
final class GrantWarning
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly NotificationService $notifications,
        private readonly AuditRecorder $audit,
    ) {}

    public function hasDocument(Application $application): bool
    {
        return $this->documents->hasDocuments($application);
    }

    public function warning(Application $application): ?string
    {
        if (ApplicationStatus::from($application->status) !== ApplicationStatus::GRANTED) {
            return null;
        }

        if ($this->hasDocument($application)) {
            return null;
        }

        return "Patent {$application->id} was granted but has no document yet; the inventor cannot download it until one is uploaded.";
    }

    public function announce(Application $application, User $actor, ?string $ipAddress = null): ?string
    {
        $warning = $this->warning($application);

        if ($warning === null) {
            return null;
        }

        $this->notifications->send(
            (string) (config('blitto.bootstrap_admin_email') ?: 'md@pdn.ac.lk'),
            "Patent {$application->id} granted without a document",
            $warning,
            'grant_without_document',
        );

        $this->audit->record(
            'grant_without_document',
            actor: $actor,
            application: $application,
            ipAddress: $ipAddress,
        );

        return $warning;
    }
}
```

- [ ] **Step 4: Wire it into `FilingWorkflowService`**

Add `private readonly GrantWarning $grantWarning` to the constructor; delete the private `warnWhenGrantedWithoutDocument()` method; make the existing public method delegate:

```php
public function grantWarning(Application $application): ?string
{
    return $this->grantWarning->warning($application);
}
```

and in `markGranted()`, replace the `$this->warnWhenGrantedWithoutDocument($application);` call with:

```php
$this->grantWarning->announce($application, $actor, $ipAddress);
```

(`markGranted` already has `$ipAddress` from Task 1.) Import `App\Domain\Vault\GrantWarning`.

- [ ] **Step 5: Run the tests**

Run: `cd backend && php artisan test --compact tests/Feature/GrantWithoutDocumentTest.php tests/Feature/FilingOutcomeTest.php tests/Feature/DocumentJourneyTest.php`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Domain/Vault/GrantWarning.php backend/app/Domain/Filing/FilingWorkflowService.php backend/tests/Feature/GrantWithoutDocumentTest.php
git commit -m "refactor(l5): move the grant-without-document warning into one shared collaborator"
```

---

### Task 8: The status route tells the truth about a document-less grant

**Files:**
- Modify: `backend/app/Domain/Notification/NotificationService.php`
- Modify: `backend/app/Domain/Lifecycle/StatusChangeService.php`
- Test: `backend/tests/Feature/StatusGrantTest.php` (create)

**Interfaces:**
- Consumes: `GrantWarning::hasDocument()` / `announce()` (Task 7).
- Produces: `NotificationService::notifyStatusChange(Application $application, ApplicationStatus $status, bool $documentAvailable = true): int` — `GRANTED` with `false` sends `Patent {ref} GRANTED — document pending` / `Good news — patent {ref} is now GRANTED. The document is pending upload by BLITTO; you can request it from your portal and we will notify you when it is ready to download.`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('warns the inventor and the admin when a status change grants without a document', function () {
    Storage::fake('vault');
    config(['blitto.bootstrap_admin_email' => 'admin@pdn.ac.lk']);

    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create([
        'title' => 'Granted bare',
        'inventor_email' => 'ada@pdn.ac.lk',
        'status' => 'ACKNOWLEDGED',
    ]);

    $this->actingAs($md)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'GRANTED',
        'reason' => 'Granted by NIPO',
    ])->assertNoContent();

    $inventor = Notification::query()->where('recipient_email', 'ada@pdn.ac.lk')->sole();

    expect($inventor->subject)->toBe("Patent {$application->id} GRANTED — document pending")
        ->and($inventor->body)->toBe("Good news — patent {$application->id} is now GRANTED. The document is pending upload by BLITTO; you can request it from your portal and we will notify you when it is ready to download.")
        ->and($inventor->kind)->toBe('status_change');

    $this->assertDatabaseHas('notifications', [
        'recipient_email' => 'admin@pdn.ac.lk',
        'kind' => 'grant_without_document',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'grant_without_document',
        'application_id' => $application->id,
        'ip_address' => '127.0.0.1',
    ]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/StatusGrantTest.php`
Expected: FAIL — the subject is `Patent {id} GRANTED — download ready`.

- [ ] **Step 3: Implement the pending variant and the shared announcement**

`NotificationService` — add the two constants and the parameter:

```php
    private const string GRANTED_PENDING_SUBJECT = 'Patent {ref} GRANTED — document pending';

    private const string GRANTED_PENDING_BODY = 'Good news — patent {ref} is now GRANTED. The document is pending upload by BLITTO; you can request it from your portal and we will notify you when it is ready to download.';

    public function notifyStatusChange(
        Application $application,
        ApplicationStatus $status,
        bool $documentAvailable = true,
    ): int {
        if ($status === ApplicationStatus::GRANTED && ! $documentAvailable) {
            $subject = str_replace('{ref}', $application->id, self::GRANTED_PENDING_SUBJECT);
            $body = str_replace('{ref}', $application->id, self::GRANTED_PENDING_BODY);
        } else {
            $subject = str_replace('{ref}', $application->id, self::STATUS_SUBJECTS[$status->value] ?? 'Patent {ref} status changed');
            $body = str_replace('{ref}', $application->id, self::STATUS_BODIES[$status->value] ?? 'The patent application {ref} is now: '.$status->value.'.');
        }

        $sent = 0;

        foreach ($this->recipients($application) as $email) {
            $this->send($email, $subject, $body);
            $sent++;
        }

        return $sent;
    }
```

`StatusChangeService` — add `GrantWarning` to the constructor and use it:

```php
$documentAvailable = $newStatus === ApplicationStatus::GRANTED
    ? $this->grantWarning->hasDocument($application)
    : true;

$this->notifications->notifyStatusChange($application, $newStatus, $documentAvailable);

if ($newStatus === ApplicationStatus::GRANTED) {
    $this->grantWarning->announce($application, $actor, $ipAddress);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact tests/Feature/StatusGrantTest.php`
Expected: PASS.

- [ ] **Step 5: Add the documented-grant test (characterization of the other branch)**

```php
it('keeps the download-ready wording when the granted application already has a document', function () {
    Storage::fake('vault');

    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create([
        'title' => 'Granted documented',
        'inventor_email' => 'ada@pdn.ac.lk',
        'status' => 'ACKNOWLEDGED',
    ]);

    $this->actingAs($md)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('patent.pdf', 'bytes'),
    ])->assertOk();

    $this->actingAs($md)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'GRANTED',
        'reason' => 'Granted by NIPO',
    ])->assertNoContent();

    expect(Notification::query()->where('recipient_email', 'ada@pdn.ac.lk')->sole()->subject)
        ->toBe("Patent {$application->id} GRANTED — download ready")
        ->and(Notification::query()->where('kind', 'grant_without_document')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'grant_without_document')->count())->toBe(0);
});
```

Add `use Illuminate\Http\UploadedFile;` to the test file.

- [ ] **Step 6: Run it (characterization: no RED available)**

Run: `cd backend && php artisan test --compact tests/Feature/StatusGrantTest.php`
Expected: PASS immediately — Step 3's `hasDocument()` branch already covers it, so this test pins the branch that must *not* change. If it fails, fix the implementation, not the test.

- [ ] **Step 7: Run the lifecycle suites, Pint and the whole suite**

Run: `cd backend && php artisan test --compact tests/Feature/StatusChangeTest.php tests/Feature/StatusEndpointTest.php tests/Feature/StatusGrantTest.php tests/Feature/GrantWithoutDocumentTest.php && vendor/bin/pint --dirty --format agent && php artisan test --compact`
Expected: all PASS (no existing status/count assertion moves, because no existing test reaches GRANTED over the status route).

- [ ] **Step 8: Commit**

```bash
git add backend/app/Domain/Notification/NotificationService.php backend/app/Domain/Lifecycle/StatusChangeService.php backend/tests/Feature/StatusGrantTest.php
git commit -m "feat(l5): tell the inventor a granted patent's document is pending when none is uploaded"
```

---

### Task 9: The portfolio analytics module

**Files:**
- Create: `backend/app/Domain/Analytics/PortfolioAnalytics.php`
- Test: `backend/tests/Feature/PortfolioAnalyticsTest.php` (create)

**Interfaces:**
- Consumes: `Application`, `Deadline`, `ApplicationStatus`, `DeadlineType`, `DeadlineStatus`.
- Produces: `PortfolioAnalytics::portfolioSummary(): array{total_applications: int, by_status: array<string, int>}` and `PortfolioAnalytics::deadlineReport(): array{open_deadlines: int, by_type: array<string, int>}`; both maps are keyed in enum declaration order and omit zero counts.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Domain\Analytics\PortfolioAnalytics;
use App\Models\Application;
use App\Models\Deadline;
use Illuminate\Support\Carbon;

it('reports an empty portfolio', function () {
    expect(app(PortfolioAnalytics::class)->portfolioSummary())
        ->toBe(['total_applications' => 0, 'by_status' => []])
        ->and(app(PortfolioAnalytics::class)->deadlineReport())
        ->toBe(['open_deadlines' => 0, 'by_type' => []]);
});

it('counts every application by status in lifecycle order', function () {
    Application::query()->create(['title' => 'Granted']);
    Application::query()->create(['title' => 'Draft A']);
    Application::query()->create(['title' => 'Draft B']);
    Application::query()->where('title', 'Granted')->update(['status' => 'GRANTED']);

    expect(app(PortfolioAnalytics::class)->portfolioSummary())->toBe([
        'total_applications' => 3,
        'by_status' => ['DRAFT' => 2, 'GRANTED' => 1],
    ]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/PortfolioAnalyticsTest.php`
Expected: FAIL — `PortfolioAnalytics` does not exist.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Domain\Analytics;

use App\Domain\Docketing\DeadlineStatus;
use App\Domain\Docketing\DeadlineType;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Models\Application;
use App\Models\Deadline;

/**
 * Portfolio reporting — port of pre-laravel-final-state
 * `app/modules/portfolio_analytics/local/__init__.py`, with two deliberate
 * fixes: maps are emitted in enum declaration order (stable JSON) and
 * `by_type` counts only OPEN deadlines (the reference counted every deadline,
 * so `open_deadlines` and `by_type` disagreed).
 */
final class PortfolioAnalytics
{
    /**
     * @return array{total_applications: int, by_status: array<string, int>}
     */
    public function portfolioSummary(): array
    {
        $counts = Application::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total_applications' => Application::query()->count(),
            'by_status' => $this->orderedCounts(ApplicationStatus::cases(), $counts),
        ];
    }

    /**
     * @return array{open_deadlines: int, by_type: array<string, int>}
     */
    public function deadlineReport(): array
    {
        $open = Deadline::query()->where('status', DeadlineStatus::OPEN->value);

        $counts = (clone $open)
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        return [
            'open_deadlines' => (clone $open)->count(),
            'by_type' => $this->orderedCounts(DeadlineType::cases(), $counts),
        ];
    }

    /**
     * @param  array<int, \UnitEnum&object{value: string}>  $cases
     * @param  \Illuminate\Support\Collection<string, int|string>  $counts
     * @return array<string, int>
     */
    private function orderedCounts(array $cases, $counts): array
    {
        $ordered = [];

        foreach ($cases as $case) {
            $count = (int) ($counts[$case->value] ?? 0);

            if ($count > 0) {
                $ordered[$case->value] = $count;
            }
        }

        return $ordered;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact tests/Feature/PortfolioAnalyticsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Add the OPEN-only deadline test (characterization)**

```php
it('counts only open deadlines by type', function () {
    $application = Application::query()->create(['title' => 'Docketed']);

    foreach ([
        ['RESPONSE', 'OPEN'],
        ['RESPONSE', 'MET'],
        ['MAINTENANCE_FEE', 'OPEN'],
        ['FILING', 'MISSED'],
    ] as [$type, $status]) {
        Deadline::query()->create([
            'application_id' => $application->id,
            'type' => $type,
            'due_date' => Carbon::now()->addDays(10),
            'status' => $status,
        ]);
    }

    expect(app(PortfolioAnalytics::class)->deadlineReport())->toBe([
        'open_deadlines' => 2,
        'by_type' => ['RESPONSE' => 1, 'MAINTENANCE_FEE' => 1],
    ]);
});
```

- [ ] **Step 6: Run it (characterization of the deliberate divergence from the reference)**

Run: `cd backend && php artisan test --compact tests/Feature/PortfolioAnalyticsTest.php`
Expected: PASS immediately — Step 3 already counts OPEN only, so this test pins the ruling against a future "port the reference exactly" refactor. If someone later makes `by_type` count every deadline, this test is the one that must stay red until that ruling is revisited.

- [ ] **Step 7: Add the status-transition test (characterization)**

```php
it('moves a count when an application changes status', function () {
    $application = Application::query()->create(['title' => 'Moving', 'inventor_email' => 'ada@pdn.ac.lk']);
    $director = App\Models\User::factory()->director()->create();

    expect(app(PortfolioAnalytics::class)->portfolioSummary()['by_status'])->toBe(['DRAFT' => 1]);

    app(App\Domain\Lifecycle\StatusChangeService::class)->change(
        $application,
        App\Domain\Lifecycle\ApplicationStatus::FILED,
        'Filed receipt recorded',
        $director,
    );

    expect(app(PortfolioAnalytics::class)->portfolioSummary()['by_status'])->toBe(['FILED' => 1]);
});
```

- [ ] **Step 8: Run the tests and commit**

Run: `cd backend && php artisan test --compact tests/Feature/PortfolioAnalyticsTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS (4 tests), Pint clean.

```bash
git add backend/app/Domain/Analytics/PortfolioAnalytics.php backend/tests/Feature/PortfolioAnalyticsTest.php
git commit -m "feat(l5): add the portfolio summary and deadline report module"
```

---

### Task 10: The analytics endpoints

**Files:**
- Create: `backend/app/Http/Controllers/AnalyticsController.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/AnalyticsEndpointTest.php` (create)

**Interfaces:**
- Consumes: `PortfolioAnalytics` (Task 9).
- Produces: `GET /analytics/portfolio` and `GET /analytics/deadlines`, both `auth` + `role:director,md`, both returning JSON objects even when the maps are empty.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Application;
use App\Models\Deadline;
use App\Models\User;
use Illuminate\Support\Carbon;

it('returns the portfolio summary for staff', function () {
    Application::query()->create(['title' => 'Draft']);
    $granted = Application::query()->create(['title' => 'Granted']);
    $granted->status = 'GRANTED';
    $granted->save();

    $this->actingAs(User::factory()->director()->create())
        ->getJson('/analytics/portfolio')
        ->assertOk()
        ->assertExactJson([
            'total_applications' => 2,
            'by_status' => ['DRAFT' => 1, 'GRANTED' => 1],
        ]);
});

it('returns the deadline report for md', function () {
    $application = Application::query()->create(['title' => 'Docketed']);
    Deadline::query()->create([
        'application_id' => $application->id,
        'type' => 'RESPONSE',
        'due_date' => Carbon::now()->addDays(5),
        'status' => 'OPEN',
    ]);

    $this->actingAs(User::factory()->md()->create())
        ->getJson('/analytics/deadlines')
        ->assertOk()
        ->assertExactJson(['open_deadlines' => 1, 'by_type' => ['RESPONSE' => 1]]);
});

it('serialises empty maps as json objects', function () {
    $portfolio = $this->actingAs(User::factory()->md()->create())
        ->getJson('/analytics/portfolio')
        ->assertOk();

    expect($portfolio->getContent())->toBe('{"total_applications":0,"by_status":{}}');

    $this->app['auth']->forgetGuards();

    $deadlines = $this->actingAs(User::factory()->director()->create())
        ->getJson('/analytics/deadlines')
        ->assertOk();

    expect($deadlines->getContent())->toBe('{"open_deadlines":0,"by_type":{}}');
});

it('refuses guests and plain users', function () {
    $this->getJson('/analytics/portfolio')->assertUnauthorized();
    $this->app['auth']->forgetGuards();

    $this->actingAs(User::factory()->create())->getJson('/analytics/portfolio')->assertForbidden();
    $this->app['auth']->forgetGuards();

    $this->actingAs(User::factory()->create())->getJson('/analytics/deadlines')->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/AnalyticsEndpointTest.php`
Expected: FAIL — 404, the routes do not exist.

- [ ] **Step 3: Implement the controller and the routes**

```php
<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\PortfolioAnalytics;
use Illuminate\Http\JsonResponse;

/**
 * Read-only portfolio reporting. Thin: every number comes from the domain
 * module; the empty-map cast keeps `{}` in the JSON instead of `[]`.
 */
final class AnalyticsController extends Controller
{
    public function __construct(private readonly PortfolioAnalytics $analytics) {}

    public function portfolio(): JsonResponse
    {
        $summary = $this->analytics->portfolioSummary();
        $summary['by_status'] = (object) $summary['by_status'];

        return response()->json($summary);
    }

    public function deadlines(): JsonResponse
    {
        $report = $this->analytics->deadlineReport();
        $report['by_type'] = (object) $report['by_type'];

        return response()->json($report);
    }
}
```

In `backend/routes/web.php` (flat style, matching the neighbouring routes):

```php
Route::get('/analytics/portfolio', [AnalyticsController::class, 'portfolio'])
    ->middleware(['auth', 'role:director,md'])
    ->name('analytics.portfolio');

Route::get('/analytics/deadlines', [AnalyticsController::class, 'deadlines'])
    ->middleware(['auth', 'role:director,md'])
    ->name('analytics.deadlines');
```

Add the `use App\Http\Controllers\AnalyticsController;` import.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact tests/Feature/AnalyticsEndpointTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Run Pint and commit**

Run: `cd backend && vendor/bin/pint --dirty --format agent`

```bash
git add backend/app/Http/Controllers/AnalyticsController.php backend/routes/web.php backend/tests/Feature/AnalyticsEndpointTest.php
git commit -m "feat(l5): expose the portfolio and deadline reports to staff"
```

---

### Task 11: The NIPO follow-up rule

**Files:**
- Create: `backend/app/Domain/Filing/NipoFollowUp.php`
- Test: `backend/tests/Feature/NipoFollowUpTest.php` (create)

**Interfaces:**
- Consumes: `NotificationService::send(...)`, `Application`, `FilingRecord`, `User`, `Role`, `ApplicationStatus`.
- Produces: `NipoFollowUp::stalled(): Collection<int, Application>` and `NipoFollowUp::remind(): int` (number of notifications written), notifying every `md` and `director` with kind `nipo_follow_up`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Domain\Filing\NipoFollowUp;
use App\Models\Application;
use App\Models\FilingRecord;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Carbon;

it('notifies every md and director about a stalled filing', function () {
    User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    User::factory()->create(['email' => 'plain@pdn.ac.lk']);

    $application = Application::query()->create([
        'title' => 'Stalled',
        'status' => 'FILED',
        'application_number' => 'LK/PAT/2026/7',
    ]);

    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => Carbon::parse('2026-09-01 08:00:00'),
        'filed_by' => 'md@pdn.ac.lk',
    ]);

    expect(app(NipoFollowUp::class)->remind())->toBe(2);

    $rows = Notification::query()->orderBy('recipient_email')->get();

    expect($rows->pluck('recipient_email')->all())->toBe(['director@pdn.ac.lk', 'md@pdn.ac.lk'])
        ->and($rows->pluck('kind')->unique()->all())->toBe(['nipo_follow_up'])
        ->and($rows->first()->subject)->toBe('NIPO Follow-up Required: Stalled')
        ->and($rows->first()->body)->toBe('Patent Stalled (LK/PAT/2026/7) was filed on 2026-09-01 but has not yet been acknowledged by NIPO. Please contact NIPO for follow-up.');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/NipoFollowUpTest.php`
Expected: FAIL — `NipoFollowUp` does not exist.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Domain\Filing;

use App\Domain\Authorization\Role;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\FilingRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * NIPO follow-up (spec §8): after filing, the reference nudged every MD and
 * director while NIPO stayed silent. Idempotency is deliberately absent —
 * a daily nudge until someone acknowledges is the point.
 */
final class NipoFollowUp
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** @return Collection<int, Application> */
    public function stalled(): Collection
    {
        $unacknowledged = FilingRecord::query()
            ->whereNull('nipo_acknowledged_at')
            ->pluck('application_id');

        return Application::query()
            ->where('status', ApplicationStatus::FILED->value)
            ->whereIn('id', $unacknowledged)
            ->orderBy('id')
            ->get();
    }

    public function remind(): int
    {
        $staff = User::query()
            ->whereIn('role', [Role::MD->value, Role::DIRECTOR->value])
            ->orderBy('email')
            ->get();

        if ($staff->isEmpty()) {
            return 0;
        }

        $sent = 0;

        foreach ($this->stalled() as $application) {
            $body = $this->body($application);

            foreach ($staff as $member) {
                $this->notifications->send(
                    $member->email,
                    "NIPO Follow-up Required: {$application->title}",
                    $body,
                    'nipo_follow_up',
                );

                $sent++;
            }
        }

        return $sent;
    }

    private function body(Application $application): string
    {
        $record = FilingRecord::query()->where('application_id', $application->id)->first();
        $filed = $record?->filed_date;

        // filed_date is a nullable column; a filing record without one must not
        // silence the reminder (Review Focus 2).
        $filedOn = $filed === null || $filed === ''
            ? 'an unrecorded date'
            : Carbon::parse($filed)->format('Y-m-d');

        $reference = $application->application_number ?: substr($application->id, 0, 8);

        return "Patent {$application->title} ({$reference}) was filed on {$filedOn} but has not yet been acknowledged by NIPO. Please contact NIPO for follow-up.";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact tests/Feature/NipoFollowUpTest.php`
Expected: PASS.

- [ ] **Step 5: Add the remaining behaviour tests (characterization)**

All six pin rules that Step 3's implementation already satisfies; none can go red on its own. They exist so a later refactor of `stalled()` or `body()` has to argue with the specification.

```php
it('ignores acknowledged filings and applications that left FILED', function () {
    User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);

    $acknowledged = Application::query()->create(['title' => 'Acked', 'status' => 'FILED']);
    FilingRecord::query()->create([
        'application_id' => $acknowledged->id,
        'filed_date' => Carbon::parse('2026-09-01'),
        'filed_by' => 'md@pdn.ac.lk',
        'nipo_acknowledged_at' => Carbon::parse('2026-09-05'),
    ]);

    $moved = Application::query()->create(['title' => 'Moved', 'status' => 'EXAMINATION']);
    FilingRecord::query()->create([
        'application_id' => $moved->id,
        'filed_date' => Carbon::parse('2026-09-01'),
        'filed_by' => 'md@pdn.ac.lk',
    ]);

    expect(app(NipoFollowUp::class)->remind())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
});

it('falls back to the first eight id characters when there is no application number', function () {
    User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Numberless', 'status' => 'FILED']);
    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => Carbon::parse('2026-09-01'),
        'filed_by' => 'md@pdn.ac.lk',
    ]);

    app(NipoFollowUp::class)->remind();

    expect(Notification::query()->sole()->body)
        ->toContain('('.substr($application->id, 0, 8).')');
});

it('still reminds when the filing record has no filed date', function () {
    User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Undated', 'status' => 'FILED']);
    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => null,
        'filed_by' => '',
    ]);

    expect(app(NipoFollowUp::class)->remind())->toBe(1)
        ->and(Notification::query()->sole()->body)->toContain('was filed on an unrecorded date');
});

it('does nothing when no staff accounts exist', function () {
    $application = Application::query()->create(['title' => 'Stalled', 'status' => 'FILED']);
    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => Carbon::parse('2026-09-01'),
        'filed_by' => 'md@pdn.ac.lk',
    ]);

    expect(app(NipoFollowUp::class)->remind())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
});

it('re-notifies on every run until the filing is acknowledged', function () {
    User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Stubborn', 'status' => 'FILED']);
    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => Carbon::parse('2026-09-01'),
        'filed_by' => 'md@pdn.ac.lk',
    ]);

    expect(app(NipoFollowUp::class)->remind())->toBe(1)
        ->and(app(NipoFollowUp::class)->remind())->toBe(1)
        ->and(Notification::query()->count())->toBe(2);
});
```

- [ ] **Step 6: Run the tests and commit**

Run: `cd backend && php artisan test --compact tests/Feature/NipoFollowUpTest.php && vendor/bin/pint --dirty --format agent`
Expected: PASS (6 tests), Pint clean.

```bash
git add backend/app/Domain/Filing/NipoFollowUp.php backend/tests/Feature/NipoFollowUpTest.php
git commit -m "feat(l5): add the NIPO follow-up rule for stalled filings"
```

---

### Task 12: The `nipo:follow-up` command and its schedule

**Files:**
- Create: `backend/app/Console/Commands/NipoFollowUpCommand.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/NipoFollowUpCommandTest.php` (create)

**Interfaces:**
- Consumes: `NipoFollowUp::remind()` (Task 11).
- Produces: artisan command `nipo:follow-up`; `NipoFollowUpCommand::expressionFor(int $hours): string` (pure, so the cadence is unit-testable); one scheduled event.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Console\Commands\NipoFollowUpCommand;
use App\Models\Application;
use App\Models\FilingRecord;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

it('reminds staff through the artisan command', function () {
    User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Stalled', 'status' => 'FILED']);
    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => Carbon::parse('2026-09-01'),
        'filed_by' => 'md@pdn.ac.lk',
    ]);

    $this->artisan('nipo:follow-up')
        ->expectsOutputToContain('1')
        ->assertSuccessful();

    expect(Notification::query()->where('kind', 'nipo_follow_up')->count())->toBe(1);
});

it('derives the schedule expression from the configured interval', function () {
    expect(NipoFollowUpCommand::expressionFor(24))->toBe('0 */24 * * *')
        ->and(NipoFollowUpCommand::expressionFor(6))->toBe('0 */6 * * *')
        ->and(NipoFollowUpCommand::expressionFor(0))->toBe('0 */1 * * *');
});

it('registers the command in the scheduler without overlap', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'nipo:follow-up'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 */24 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/NipoFollowUpCommandTest.php`
Expected: FAIL — the command class and the schedule entry do not exist.

- [ ] **Step 3: Implement the command and register it**

```php
<?php

namespace App\Console\Commands;

use App\Domain\Filing\NipoFollowUp;
use Illuminate\Console\Command;

final class NipoFollowUpCommand extends Command
{
    protected $signature = 'nipo:follow-up';

    protected $description = 'Remind staff about applications filed with NIPO but not yet acknowledged';

    /**
     * The cadence expression for a check-every-N-hours interval: 24 hours is a
     * daily run, anything smaller steps within the day.
     */
    public static function expressionFor(int $hours): string
    {
        return '0 */'.max(1, $hours).' * * *';
    }

    public function handle(NipoFollowUp $nipo): int
    {
        $sent = $nipo->remind();

        $this->info("NIPO follow-up: {$sent} reminder(s) written.");

        return self::SUCCESS;
    }
}
```

`backend/routes/console.php` — append:

```php
use App\Console\Commands\NipoFollowUpCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command('nipo:follow-up')
    ->cron(NipoFollowUpCommand::expressionFor((int) config('blitto.nipo_follow_up_interval_hours')))
    ->withoutOverlapping();
```

Keep the existing `inspire` command in the file.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact tests/Feature/NipoFollowUpCommandTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Confirm the schedule lists it**

Run: `cd backend && php artisan schedule:list`
Expected: one row for `nipo:follow-up` with the cron expression `0 */24 * * *`.

- [ ] **Step 6: Run Pint, the surrounding suites and commit**

Run: `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact tests/Feature/NipoFollowUpTest.php tests/Feature/NipoFollowUpCommandTest.php`

```bash
git add backend/app/Console/Commands/NipoFollowUpCommand.php backend/routes/console.php backend/tests/Feature/NipoFollowUpCommandTest.php
git commit -m "feat(l5): schedule the NIPO follow-up command at the configured interval"
```

---

### Task 13: The demo seeder, part one — users, applications, inventors, history

**Files:**
- Create: `backend/database/seeders/DemoDataSeeder.php`
- Create: `backend/tests/Fixtures/demo-snapshot.json`
- Modify: `backend/config/blitto.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Modify: `backend/.env.example`
- Test: `backend/tests/Feature/DemoDataSeederTest.php` (create)

**Interfaces:**
- Consumes: `UserDirectory::register(email: ..., role: ..., password: ..., userCode: ..., actor: ..., ipAddress: ...)` (Task 2).
- Produces: `DemoDataSeeder` reading `blitto.demo_snapshot_path` (absolute path or relative to `base_path()`), aborting on a version other than `1`, restoring users / applications / inventors / status_history idempotently; `DatabaseSeeder::run()` calls it.

- [ ] **Step 1: Write the fixture**

`backend/tests/Fixtures/demo-snapshot.json` — small, complete and shaped exactly like `dev-data/seed_snapshot.json` (dicts keyed by uuid, `notification.sent` a list):

```json
{
  "version": 1,
  "authorization": {
    "users": {
      "11111111-1111-4111-8111-111111111111": {
        "id": "11111111-1111-4111-8111-111111111111",
        "email": "md@pdn.ac.lk", "role": "md", "user_code": null,
        "created_at": "2026-09-09T08:56:40.868678Z"
      },
      "22222222-2222-4222-8222-222222222222": {
        "id": "22222222-2222-4222-8222-222222222222",
        "email": "user-a@pdn.ac.lk", "role": "user", "user_code": null,
        "created_at": "2026-09-09T08:56:40.868678Z"
      }
    },
    "passwords": {
      "11111111-1111-4111-8111-111111111111": "mdpw-123",
      "22222222-2222-4222-8222-222222222222": "userapw-123"
    }
  },
  "application_intake": {
    "store": {
      "33333333-3333-4333-8333-333333333333": {
        "id": "33333333-3333-4333-8333-333333333333",
        "title": "Solar Desalination Membrane",
        "inventor_name": "User A",
        "inventor_email": "user-a@pdn.ac.lk",
        "inventors": [
          {"inventor_name": "User A", "inventor_email": "user-a@pdn.ac.lk"}
        ],
        "status": "DRAFT",
        "application_number": null,
        "nipo_reference": null,
        "technology_area": "",
        "created_at": "2026-09-09T08:56:40.868868Z",
        "updated_at": "2026-09-09T08:56:40.868869Z"
      }
    },
    "history": {
      "33333333-3333-4333-8333-333333333333": [
        {
          "id": "44444444-4444-4444-8444-444444444444",
          "application_id": "33333333-3333-4333-8333-333333333333",
          "old_status": "DRAFT", "new_status": "GRANTED",
          "changed_by": "md@pdn.ac.lk",
          "changed_at": "2026-09-09T08:56:40.869062Z"
        }
      ]
    }
  },
  "prosecution": {"actions": {}, "responses": {}},
  "notification": {
    "sent": [
      {
        "id": "66666666-6666-4666-8666-666666666666",
        "recipient_email": "user-a@pdn.ac.lk",
        "subject": "Patent 33333333-3333-4333-8333-333333333333 GRANTED — download ready",
        "body": "Good news — patent 33333333-3333-4333-8333-333333333333 is now GRANTED.",
        "kind": "status_change",
        "sent_at": "2026-09-09T08:56:40.869088Z"
      }
    ]
  },
  "audit": {
    "log": {
      "77777777-7777-4777-8777-777777777777": {
        "id": "77777777-7777-4777-8777-777777777777",
        "user_id": "md",
        "application_id": "33333333-3333-4333-8333-333333333333",
        "action": "store_document",
        "timestamp": "2026-09-09T08:56:40.869179Z",
        "ip_address": null
      }
    }
  },
  "filing_workflow": {"records": {}, "defect_sheets": {}},
  "docketing": {
    "deadlines": {
      "88888888-8888-4888-8888-888888888888": {
        "id": "88888888-8888-4888-8888-888888888888",
        "application_id": "33333333-3333-4333-8333-333333333333",
        "type": "RESPONSE",
        "due_date": "2026-12-08T08:56:40.868979Z",
        "status": "OPEN",
        "created_at": "2026-09-09T08:56:40.869002Z"
      }
    }
  },
  "document_vault": {
    "documents": {
      "99999999-9999-4999-8999-999999999999": {
        "id": "99999999-9999-4999-8999-999999999999",
        "application_id": "33333333-3333-4333-8333-333333333333",
        "filename": "spec.pdf",
        "file_size": 14,
        "content_ref": "mem:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
        "uploaded_by": "md@pdn.ac.lk",
        "uploaded_at": "2026-09-09T08:56:40.868959Z"
      },
      "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb": {
        "id": "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
        "application_id": "33333333-3333-4333-8333-333333333333",
        "filename": "corrupt.pdf",
        "file_size": 0,
        "content_ref": "mem:missing-content",
        "uploaded_by": "md@pdn.ac.lk",
        "uploaded_at": "2026-09-09T08:56:40.868960Z"
      }
    },
    "contents": {
      "mem:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa": "ZGVtby1wZGYtYnl0ZXM="
    }
  }
}
```

(`ZGVtby1wZGYtYnl0ZXM=` is base64 for `demo-pdf-bytes`, 14 bytes. The second document's `content_ref` deliberately has no content — Task 15's Review Focus case.)

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Models\Application;
use App\Models\StatusHistory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['blitto.demo_snapshot_path' => base_path('tests/Fixtures/demo-snapshot.json')]);
});

it('restores the snapshot users with bcrypted passwords', function () {
    $this->seed(DemoDataSeeder::class);

    expect(User::query()->count())->toBe(2);

    $md = User::query()->where('email', 'md@pdn.ac.lk')->sole();

    expect($md->role)->toBe('md')
        ->and(Hash::check('mdpw-123', $md->password_hash))->toBeTrue()
        ->and($md->password_hash)->not->toBe('mdpw-123');

    $inventor = User::query()->where('email', 'user-a@pdn.ac.lk')->sole();

    expect(Hash::check('userapw-123', $inventor->password_hash))->toBeTrue();
});

it('restores applications with their inventors and history', function () {
    $this->seed(DemoDataSeeder::class);

    $application = Application::query()->sole();

    expect($application->id)->toBe('33333333-3333-4333-8333-333333333333')
        ->and($application->title)->toBe('Solar Desalination Membrane')
        ->and($application->status)->toBe('DRAFT')
        ->and($application->inventor_email)->toBe('user-a@pdn.ac.lk')
        ->and($application->created_at->toIso8601ZuluString())->toBe('2026-09-09T08:56:40Z');

    $this->assertDatabaseHas('inventors', [
        'application_id' => $application->id,
        'inventor_email' => 'user-a@pdn.ac.lk',
        'inventor_name' => 'User A',
    ]);

    $history = StatusHistory::query()->sole();

    expect($history->id)->toBe('44444444-4444-4444-8444-444444444444')
        ->and($history->old_status)->toBe('DRAFT')
        ->and($history->new_status)->toBe('GRANTED')
        ->and($history->reason)->toBe('');
});

it('imports the same rows through the default database seeder', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Application::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(2);
});

it('aborts with a clear message when the snapshot file is missing', function () {
    config(['blitto.demo_snapshot_path' => base_path('tests/Fixtures/nope.json')]);

    expect(fn () => $this->seed(DemoDataSeeder::class))
        ->toThrow(RuntimeException::class, 'Demo snapshot not found');
});

it('aborts on an unsupported snapshot version', function () {
    config(['blitto.demo_snapshot_path' => base_path('tests/Fixtures/demo-snapshot-v2.json')]);

    file_put_contents(
        base_path('tests/Fixtures/demo-snapshot-v2.json'),
        json_encode(['version' => 2]),
    );

    try {
        expect(fn () => $this->seed(DemoDataSeeder::class))
            ->toThrow(RuntimeException::class, 'Unsupported demo snapshot version');
    } finally {
        unlink(base_path('tests/Fixtures/demo-snapshot-v2.json'));
    }
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/DemoDataSeederTest.php`
Expected: FAIL — `DemoDataSeeder` does not exist.

- [ ] **Step 4: Implement**

Add the config key in `backend/config/blitto.php`:

```php
    // Reference demo snapshot restored by DemoDataSeeder (relative paths resolve
    // against backend/).
    'demo_snapshot_path' => env('BLITTODB_DEMO_SNAPSHOT_PATH', '../dev-data/seed_snapshot.json'),
```

`backend/database/seeders/DemoDataSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Domain\Authorization\Role;
use App\Domain\Authorization\UserDirectory;
use App\Models\Application;
use App\Models\Inventor;
use App\Models\StatusHistory;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Restores the reference demo state from dev-data/seed_snapshot.json (spec §9).
 * Rows keep the snapshot's own uuids, so a second run is a no-op.
 */
final class DemoDataSeeder extends Seeder
{
    public const int SUPPORTED_VERSION = 1;

    public function run(): void
    {
        $this->import($this->loadSnapshot());
    }

    /** @param array<string, mixed> $snapshot */
    public function import(array $snapshot): void
    {
        if (($snapshot['version'] ?? null) !== self::SUPPORTED_VERSION) {
            throw new RuntimeException('Unsupported demo snapshot version: '.json_encode($snapshot['version'] ?? null));
        }

        $emails = $this->importUsers((array) ($snapshot['authorization'] ?? []));
        $applications = $this->importApplications((array) ($snapshot['application_intake'] ?? []), $emails);

        $this->command?->info(sprintf(
            'Demo data: %d user(s), %d application(s).',
            count($emails),
            $applications,
        ));
    }

    /** @return array<string, mixed> */
    private function loadSnapshot(): array
    {
        $configured = (string) config('blitto.demo_snapshot_path');
        $path = str_starts_with($configured, '/') ? $configured : base_path($configured);

        if (! is_file($path)) {
            throw new RuntimeException("Demo snapshot not found at {$path}.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Demo snapshot at {$path} is not valid JSON.");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $authorization
     * @return array<string, string> snapshot email => stored email
     */
    private function importUsers(array $authorization): array
    {
        $rows = (array) ($authorization['users'] ?? []);
        $passwords = (array) ($authorization['passwords'] ?? []);
        $directory = app(UserDirectory::class);
        $emails = [];

        foreach ($rows as $id => $row) {
            $email = (string) ($row['email'] ?? '');

            if ($directory->findByEmail($email) === null) {
                $directory->register(
                    email: $email,
                    role: Role::from((string) ($row['role'] ?? 'user')),
                    password: isset($passwords[$id]) ? (string) $passwords[$id] : null,
                    userCode: isset($row['user_code']) ? (string) $row['user_code'] : null,
                );
            }

            $emails[(string) $id] = $email;
        }

        return $emails;
    }

    /**
     * @param  array<string, mixed>  $intake
     * @param  array<string, string>  $emails
     */
    private function importApplications(array $intake, array $emails): int
    {
        $store = (array) ($intake['store'] ?? []);
        $history = (array) ($intake['history'] ?? []);
        $count = 0;

        foreach ($store as $id => $row) {
            $id = (string) $id;

            $application = Application::query()->find($id) ?? new Application;
            $application->id = $id;
            $application->fill([
                'title' => (string) ($row['title'] ?? ''),
                'inventor_name' => (string) ($row['inventor_name'] ?? ''),
                'inventor_email' => (string) ($row['inventor_email'] ?? ''),
                'status' => (string) ($row['status'] ?? 'DRAFT'),
                'application_number' => $row['application_number'] ?? null,
                'nipo_reference' => $row['nipo_reference'] ?? null,
                'technology_area' => (string) ($row['technology_area'] ?? ''),
            ]);
            $application->created_at = $row['created_at'] ?? null;
            $application->updated_at = $row['updated_at'] ?? ($row['created_at'] ?? null);
            $application->save();

            foreach ((array) ($row['inventors'] ?? []) as $inventor) {
                Inventor::query()->updateOrCreate(
                    ['application_id' => $id, 'inventor_email' => (string) ($inventor['inventor_email'] ?? '')],
                    ['inventor_name' => (string) ($inventor['inventor_name'] ?? '')],
                );
            }

            foreach ((array) ($history[$id] ?? []) as $entry) {
                $entryRow = StatusHistory::query()->find((string) $entry['id']) ?? new StatusHistory;
                $entryRow->id = (string) $entry['id'];
                $entryRow->fill([
                    'application_id' => (string) $entry['application_id'],
                    'old_status' => $entry['old_status'] ?? null,
                    'new_status' => (string) $entry['new_status'],
                    'changed_by' => (string) $entry['changed_by'],
                    // The snapshot predates status_history.reason, which is NOT
                    // NULL with no default.
                    'reason' => '',
                ]);
                $entryRow->changed_at = $entry['changed_at'] ?? null;
                $entryRow->save();
            }

            $count++;
        }

        return $count;
    }
}
```

`backend/database/seeders/DatabaseSeeder.php` — replace the body of `run()`:

```php
    public function run(): void
    {
        $this->call(DemoDataSeeder::class);
    }
```

(remove the now-unused `User` import).

`backend/.env.example` — append to the commented block:

```
# BLITTODB_DEMO_SNAPSHOT_PATH=../dev-data/seed_snapshot.json
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && php artisan test --compact tests/Feature/DemoDataSeederTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Run Pint and commit**

```bash
git add backend/database/seeders backend/config/blitto.php backend/.env.example backend/tests/Fixtures/demo-snapshot.json backend/tests/Feature/DemoDataSeederTest.php
git commit -m "feat(l5): restore the reference demo users, applications and history"
```

---

### Task 14: The demo seeder, part two — deadlines, notifications, audit rows

**Files:**
- Modify: `backend/database/seeders/DemoDataSeeder.php`
- Test: `backend/tests/Feature/DemoDataSeederTest.php` (add)

**Interfaces:**
- Consumes: the fixture from Task 13.
- Produces: the seeder also restores `docketing.deadlines`, `notification.sent` and `audit.log`, each upserted by its snapshot uuid; sections that are absent are skipped.

- [ ] **Step 1: Write the failing test**

```php
it('restores deadlines, notifications and audit rows with their timestamps', function () {
    $this->seed(DemoDataSeeder::class);

    $deadline = App\Models\Deadline::query()->sole();

    expect($deadline->id)->toBe('88888888-8888-4888-8888-888888888888')
        ->and($deadline->type)->toBe('RESPONSE')
        ->and($deadline->status)->toBe('OPEN')
        ->and($deadline->due_date->toIso8601ZuluString())->toBe('2026-12-08T08:56:40Z');

    $notification = App\Models\Notification::query()->sole();

    expect($notification->id)->toBe('66666666-6666-4666-8666-666666666666')
        ->and($notification->recipient_email)->toBe('user-a@pdn.ac.lk')
        ->and($notification->subject)->toContain('GRANTED — download ready')
        ->and($notification->sent_at->toIso8601ZuluString())->toBe('2026-09-09T08:56:40Z');

    $this->assertDatabaseHas('audit_logs', [
        'id' => '77777777-7777-4777-8777-777777777777',
        'user_id' => 'md',
        'application_id' => '33333333-3333-4333-8333-333333333333',
        'action' => 'store_document',
        'ip_address' => null,
    ]);
});

it('imports a snapshot that omits optional sections', function () {
    $minimal = [
        'version' => 1,
        'authorization' => [
            'users' => [
                '11111111-1111-4111-8111-111111111111' => [
                    'id' => '11111111-1111-4111-8111-111111111111',
                    'email' => 'md@pdn.ac.lk', 'role' => 'md', 'user_code' => null,
                ],
            ],
            'passwords' => ['11111111-1111-4111-8111-111111111111' => 'mdpw-123'],
        ],
        'application_intake' => ['store' => [], 'history' => []],
    ];

    file_put_contents(base_path('tests/Fixtures/demo-snapshot-minimal.json'), json_encode($minimal));
    config(['blitto.demo_snapshot_path' => base_path('tests/Fixtures/demo-snapshot-minimal.json')]);

    try {
        $this->seed(DemoDataSeeder::class);

        expect(User::query()->count())->toBe(1)
            ->and(App\Models\Deadline::query()->count())->toBe(0)
            ->and(App\Models\Notification::query()->count())->toBe(0);
    } finally {
        unlink(base_path('tests/Fixtures/demo-snapshot-minimal.json'));
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/DemoDataSeederTest.php`
Expected: FAIL — deadlines/notifications/audit rows are not imported yet (the minimal-snapshot test already passes).

- [ ] **Step 3: Implement the three remaining sections**

Add to `import()` after the applications call, and add the three private methods:

```php
        $this->importDeadlines((array) ($snapshot['docketing'] ?? []));
        $this->importNotifications((array) ($snapshot['notification'] ?? []));
        $this->importAuditRows((array) ($snapshot['audit'] ?? []));
```

```php
    /** @param array<string, mixed> $docketing */
    private function importDeadlines(array $docketing): void
    {
        foreach ((array) ($docketing['deadlines'] ?? []) as $id => $row) {
            $deadline = Deadline::query()->find((string) $id) ?? new Deadline;
            $deadline->id = (string) $id;
            $deadline->fill([
                'application_id' => (string) $row['application_id'],
                'type' => (string) $row['type'],
                'due_date' => $row['due_date'],
                'status' => (string) ($row['status'] ?? 'OPEN'),
            ]);
            $deadline->created_at = $row['created_at'] ?? null;
            $deadline->save();
        }
    }

    /** @param array<string, mixed> $notification */
    private function importNotifications(array $notification): void
    {
        foreach ((array) ($notification['sent'] ?? []) as $row) {
            $record = Notification::query()->find((string) $row['id']) ?? new Notification;
            $record->id = (string) $row['id'];
            $record->fill([
                'recipient_email' => (string) $row['recipient_email'],
                'subject' => (string) $row['subject'],
                'body' => (string) $row['body'],
                'kind' => (string) ($row['kind'] ?? 'status_change'),
            ]);
            $record->sent_at = $row['sent_at'] ?? null;
            $record->save();
        }
    }

    /** @param array<string, mixed> $audit */
    private function importAuditRows(array $audit): void
    {
        foreach ((array) ($audit['log'] ?? []) as $id => $row) {
            $log = AuditLog::query()->find((string) $id) ?? new AuditLog;
            $log->id = (string) $id;
            $log->fill([
                'user_id' => $row['user_id'] ?? null,
                'application_id' => $row['application_id'] ?? null,
                'action' => (string) $row['action'],
                'ip_address' => $row['ip_address'] ?? null,
            ]);
            $log->timestamp = $row['timestamp'] ?? null;
            $log->save();
        }
    }
```

Imports: `App\Models\AuditLog`, `App\Models\Deadline`, `App\Models\Notification`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact tests/Feature/DemoDataSeederTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders/DemoDataSeeder.php backend/tests/Feature/DemoDataSeederTest.php
git commit -m "feat(l5): restore demo deadlines, notifications and audit rows"
```

---

### Task 15: The demo seeder, part three — documents, idempotency, the demo-inventor override

**Files:**
- Modify: `backend/database/seeders/DemoDataSeeder.php`
- Test: `backend/tests/Feature/DemoDataSeederTest.php` (add)

**Interfaces:**
- Consumes: `DocumentStore::put(string $contents): string` (L4), `DocumentService::tryBytesFor(Document): ?string` (L4).
- Produces: document rows whose bytes live in the vault store; a re-run that changes nothing; the `blitto.demo_inventor1_*` / `demo_inventor2_*` override.

- [ ] **Step 1: Write the failing test**

```php
use Illuminate\Support\Facades\Storage;

it('writes document bytes through the document store', function () {
    Storage::fake('vault');

    $this->seed(DemoDataSeeder::class);

    $document = App\Models\Document::query()->find('99999999-9999-4999-8999-999999999999');

    expect($document)->not->toBeNull()
        ->and($document->filename)->toBe('spec.pdf')
        ->and($document->file_size)->toBe(14)
        ->and($document->content_ref)->toMatch('/^[0-9a-f-]{36}$/')
        ->and(app(App\Domain\Vault\DocumentService::class)->tryBytesFor($document))->toBe('demo-pdf-bytes');
});

it('skips a document whose content is missing instead of writing a row without bytes', function () {
    Storage::fake('vault');

    $this->seed(DemoDataSeeder::class);

    expect(App\Models\Document::query()->find('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'))->toBeNull()
        ->and(App\Models\Document::query()->count())->toBe(1);
});

it('is idempotent across runs', function () {
    Storage::fake('vault');

    $this->seed(DemoDataSeeder::class);
    $counts = [
        'users' => User::query()->count(),
        'applications' => Application::query()->count(),
        'inventors' => App\Models\Inventor::query()->count(),
        'status_history' => StatusHistory::query()->count(),
        'deadlines' => App\Models\Deadline::query()->count(),
        'notifications' => App\Models\Notification::query()->count(),
        'documents' => App\Models\Document::query()->count(),
        'audit_logs' => App\Models\AuditLog::query()->count(),
    ];

    $this->seed(DemoDataSeeder::class);

    expect([
        'users' => User::query()->count(),
        'applications' => Application::query()->count(),
        'inventors' => App\Models\Inventor::query()->count(),
        'status_history' => StatusHistory::query()->count(),
        'deadlines' => App\Models\Deadline::query()->count(),
        'notifications' => App\Models\Notification::query()->count(),
        'documents' => App\Models\Document::query()->count(),
        'audit_logs' => App\Models\AuditLog::query()->count(),
    ])->toBe($counts);
});

it('relocates the inventor accounts when the demo inventor is configured', function () {
    config([
        'blitto.demo_inventor1_email' => 'seed-a@pdn.ac.lk',
        'blitto.demo_inventor1_password' => 'demo-password',
        'blitto.demo_inventor1_name' => 'Demo Inventor',
    ]);

    $this->seed(DemoDataSeeder::class);

    expect(User::query()->where('email', 'user-a@pdn.ac.lk')->exists())->toBeFalse();

    $inventor = User::query()->where('email', 'seed-a@pdn.ac.lk')->sole();

    expect(Hash::check('demo-password', $inventor->password_hash))->toBeTrue()
        ->and(Application::query()->sole()->inventor_email)->toBe('seed-a@pdn.ac.lk')
        ->and(Application::query()->sole()->inventor_name)->toBe('Demo Inventor');
});

it('imports the shipped dev-data snapshot', function () {
    Storage::fake('vault');
    config(['blitto.demo_snapshot_path' => '../dev-data/seed_snapshot.json']);

    $this->seed(DemoDataSeeder::class);

    expect(User::query()->count())->toBe(4)
        ->and(Application::query()->count())->toBe(3)
        ->and(App\Models\Deadline::query()->count())->toBe(2)
        ->and(App\Models\Notification::query()->count())->toBe(5)
        ->and(App\Models\Document::query()->count())->toBe(2)
        // 2 restored from the snapshot plus one create_user row per account.
        ->and(App\Models\AuditLog::query()->count())->toBe(6);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --compact tests/Feature/DemoDataSeederTest.php`
Expected: FAIL — documents are not imported; there is no override.

- [ ] **Step 3: Implement the override and the documents**

Override work lives in `import()`, and `importUsers()` takes the overrides in:

```php
    public function import(array $snapshot): void
    {
        if (($snapshot['version'] ?? null) !== self::SUPPORTED_VERSION) {
            throw new RuntimeException('Unsupported demo snapshot version: '.json_encode($snapshot['version'] ?? null));
        }

        $authorization = (array) ($snapshot['authorization'] ?? []);
        $overrides = $this->demoOverrides((array) ($authorization['users'] ?? []));

        $emails = $this->importUsers($authorization, $overrides);
        $applications = $this->importApplications((array) ($snapshot['application_intake'] ?? []), $emails, $overrides);

        $this->importDeadlines((array) ($snapshot['docketing'] ?? []));
        $this->importNotifications((array) ($snapshot['notification'] ?? []));
        $this->importAuditRows((array) ($snapshot['audit'] ?? []));
        $this->importDocuments((array) ($snapshot['document_vault'] ?? []));

        $this->command?->info(sprintf(
            'Demo data: %d user(s), %d application(s).',
            count($emails),
            $applications,
        ));
    }

    /**
     * The configured demo inventors displace the snapshot's user-role accounts
     * in order, so seeded applications follow the demo configuration.
     *
     * @param  array<string, mixed>  $rows
     * @return array<string, array{email: string, password: ?string, name: string}>
     */
    private function demoOverrides(array $rows): array
    {
        $inventorIds = [];

        foreach ($rows as $id => $row) {
            if (($row['role'] ?? null) === Role::USER->value) {
                $inventorIds[] = (string) $id;
            }
        }

        $overrides = [];

        foreach ([[1, 'demo_inventor1_'], [2, 'demo_inventor2_']] as [$index, $prefix]) {
            $email = (string) config("blitto.{$prefix}email", '');

            if ($email === '' || ! isset($inventorIds[$index - 1])) {
                continue;
            }

            $overrides[$inventorIds[$index - 1]] = [
                'email' => $email,
                'password' => (string) config("blitto.{$prefix}password", '') ?: null,
                'name' => (string) config("blitto.{$prefix}name", ''),
            ];
        }

        return $overrides;
    }
```

Then `importUsers()` takes the overrides and applies them per account:

```php
    // inside the foreach ($rows as $id => $row) loop:
    $override = $overrides[(string) $id] ?? null;
    $email = $override['email'] ?? (string) ($row['email'] ?? '');
    $password = $override['password'] ?? (isset($passwords[$id]) ? (string) $passwords[$id] : null);
```

`importApplications()` becomes (replacing Task 13's version in full):

```php
    private function importApplications(array $intake, array $emails, array $overrides): int
    {
        $store = (array) ($intake['store'] ?? []);
        $history = (array) ($intake['history'] ?? []);
        $emailMap = [];
        $nameMap = [];

        foreach ($emails as $id => $email) {
            $emailMap[$email] = $overrides[$id]['email'] ?? $email;

            if (($overrides[$id]['name'] ?? '') !== '') {
                $nameMap[$email] = $overrides[$id]['name'];
            }
        }

        $count = 0;

        foreach ($store as $id => $row) {
            $id = (string) $id;
            $owner = (string) ($row['inventor_email'] ?? '');

            $application = Application::query()->find($id) ?? new Application;
            $application->id = $id;
            $application->fill([
                'title' => (string) ($row['title'] ?? ''),
                'inventor_name' => $nameMap[$owner] ?? (string) ($row['inventor_name'] ?? ''),
                'inventor_email' => $emailMap[$owner] ?? $owner,
                'status' => (string) ($row['status'] ?? 'DRAFT'),
                'application_number' => $row['application_number'] ?? null,
                'nipo_reference' => $row['nipo_reference'] ?? null,
                'technology_area' => (string) ($row['technology_area'] ?? ''),
            ]);
            $application->created_at = $row['created_at'] ?? null;
            $application->updated_at = $row['updated_at'] ?? ($row['created_at'] ?? null);
            $application->save();

            foreach ((array) ($row['inventors'] ?? []) as $inventor) {
                $nested = (string) ($inventor['inventor_email'] ?? '');

                Inventor::query()->updateOrCreate(
                    ['application_id' => $id, 'inventor_email' => $emailMap[$nested] ?? $nested],
                    ['inventor_name' => $nameMap[$nested] ?? (string) ($inventor['inventor_name'] ?? '')],
                );
            }

            foreach ((array) ($history[$id] ?? []) as $entry) {
                $entryRow = StatusHistory::query()->find((string) $entry['id']) ?? new StatusHistory;
                $entryRow->id = (string) $entry['id'];
                $entryRow->fill([
                    'application_id' => (string) $entry['application_id'],
                    'old_status' => $entry['old_status'] ?? null,
                    'new_status' => (string) $entry['new_status'],
                    'changed_by' => (string) $entry['changed_by'],
                    'reason' => '',
                ]);
                $entryRow->changed_at = $entry['changed_at'] ?? null;
                $entryRow->save();
            }

            $count++;
        }

        return $count;
    }
```

Documents in `import()`, after the audit rows:

```php
        $this->importDocuments((array) ($snapshot['document_vault'] ?? []));
```

```php
    /** @param array<string, mixed> $vault */
    private function importDocuments(array $vault): void
    {
        $contents = (array) ($vault['contents'] ?? []);
        $documents = app(DocumentService::class);
        $store = app(DocumentStore::class);

        foreach ((array) ($vault['documents'] ?? []) as $id => $row) {
            $id = (string) $id;
            $existing = Document::query()->find($id);

            if ($existing !== null && $documents->tryBytesFor($existing) !== null) {
                continue;
            }

            $encoded = $contents[(string) ($row['content_ref'] ?? '')] ?? null;
            $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;

            if ($bytes === false || $bytes === '') {
                $this->command?->warn("Demo data: skipping document {$id} (no readable content).");

                continue;
            }

            $document = $existing ?? new Document;
            $document->id = $id;
            $document->fill([
                'application_id' => (string) $row['application_id'],
                'filename' => (string) $row['filename'],
                'file_size' => strlen($bytes),
                'content_ref' => $store->put($bytes),
                'uploaded_by' => (string) ($row['uploaded_by'] ?? ''),
            ]);
            $document->uploaded_at = $row['uploaded_at'] ?? null;
            $document->save();
        }
    }
```

Imports: `App\Domain\Vault\DocumentService`, `App\Domain\Vault\DocumentStore`, `App\Models\Document`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --compact tests/Feature/DemoDataSeederTest.php`
Expected: PASS (12 tests).

- [ ] **Step 5: Run Pint, the whole suite and commit**

Run: `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact`
Expected: all PASS, 0 failures.

```bash
git add backend/database/seeders/DemoDataSeeder.php backend/tests/Feature/DemoDataSeederTest.php
git commit -m "feat(l5): restore demo documents through the document store and honour the demo inventor"
```

---

### Task 16: Decision record, project docs, and the final sweep

**Files:**
- Create: `backend/../docs/adr/0005-mail-seam-and-notification-delivery.md`
- Modify: `BIGGER_PICTURE.md` (§6 pipeline row, §9 status)
- Modify: `CONTEXT.md` (External Dependencies table)
- Modify: `docs/superpowers/specs/2026-10-18-l5-cross-cutting-design.md` (§9 mapping note)

- [ ] **Step 1: Write the ADR**

`docs/adr/0005-mail-seam-and-notification-delivery.md` — follow the structure of `docs/adr/0004-documents-stored-in-the-clear.md` (Context / Decision / Consequences). Record: the `MailChannel` port with a Laravel-backed production adapter and an in-memory test adapter; delivery is best-effort (the `notifications` row is the durable record, a mail failure is logged and swallowed); the two deliberate divergences from the reference — `deadline_report.by_type` counts OPEN deadlines only, and the NIPO reminder uses kind `nipo_follow_up` rather than the reference's `status_change` default — plus the absence of scheduler dedup, which is intentional.

- [ ] **Step 2: Update the pipeline docs**

- `BIGGER_PICTURE.md` §6: set the L5 row's deliverable to describe what shipped (mail seam + best-effort delivery, audit write gaps closed, analytics endpoints, `nipo:follow-up` scheduler, snapshot-driven demo seeder; encryption still deferred by ADR 0004).
- `BIGGER_PICTURE.md` §9: add a **Done: L5 (#69)** entry with the final suite numbers, and set the next move to L6 (#70).
- `CONTEXT.md`: in the External Dependencies table, replace the `app/adapters/email/` row with the Laravel seam (`App\Domain\Notification\MailChannel`, mock `array` adapter in tests) and correct the document-storage row to name `App\Domain\Vault\DocumentStore` with the plaintext `local` adapter (ADR 0004).

- [ ] **Step 3: Correct the spec's mapping note**

In `docs/superpowers/specs/2026-10-18-l5-cross-cutting-design.md` §9, the mapping row for `filing_workflow.records, prosecution.*` says those sections are "imported generically when present". Change it to record what the plan decided: the shipped snapshot's `filing_workflow.records` and `prosecution.actions/responses` are **empty**, so the seeder deliberately has no import code for them and ignores sections it does not know; the row must be added only when a demo dataset actually contains filings.

- [ ] **Step 4: Refresh the knowledge graph and re-run everything**

Run: `cd /home/dushmilan/.local/share/opencode/worktree/9df89c/feat-l4-document-vault && graphify update .`
Then: `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact`
Expected: graph updated, Pint clean, the whole suite PASS (baseline 350 plus roughly 40 new tests).

- [ ] **Step 5: Verify the seeder end to end on a real database**

Run: `cd backend && php artisan migrate:fresh --seed && php artisan db:seed --class=DemoDataSeeder && php artisan tinker --execute="echo App\Models\User::count().' users / '.App\Models\Application::count().' applications';"`
Expected: the first seed reports 4 users / 3 applications; the second run prints the same counts (idempotent). Delete `backend/database/database.sqlite` afterwards if the run created one.

- [ ] **Step 6: Commit**

```bash
git add docs/adr/0005-mail-seam-and-notification-delivery.md BIGGER_PICTURE.md CONTEXT.md docs/superpowers/specs/2026-10-18-l5-cross-cutting-design.md
git commit -m "docs(l5): record the mail seam decision and mark the layer done"
```
