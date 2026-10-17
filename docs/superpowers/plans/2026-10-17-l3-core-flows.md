# L3 — Core Flows Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Laravel backend the patent core flows — applications CRUD + history, status changes, docketing, office actions, and the file→acknowledge→defect-sheets→grant/reject filing workflow — each state change notifying every inventor and writing an audit row.

**Architecture:** Thin controllers validate input, resolve the application through `App\Domain\ApplicationIntake\ApplicationIntakeService`, and delegate to deep domain services (`Lifecycle\StatusChangeService` orchestrating the pure L1 `LifecycleService`, `Docketing\DocketingService`, `Prosecution\ProsecutionService`, `Filing\FilingWorkflowService`) that own their audit (`Audit\AuditRecorder`) and notification (`Notification\NotificationService`) side effects. Eloquent models stay persistence-only; routes mirror the FastAPI reference paths (including `/admin/filing/...`) so the L6 UI and the reference journeys map 1:1.

**Tech Stack:** Laravel 13.35, PHP 8.3.6, SQLite + Eloquent, Pest 4, Pint.

**Spec:** `docs/superpowers/specs/2026-10-09-blitto-patent-database-laravel-migration-design.md` (§5 users/data model, §7 Domain behavior, §9 testing). Tracking issue: [#67](https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/67). Context: `BIGGER_PICTURE.md` §6/§9, `CONTEXT.md` (invariants 1–6). Behavioral reference (never a gate — "journeys are the reference, not a porting checklist"): git tag `pre-laravel-final-state` — `app/api/routes.py`, `app/modules/{application_intake,docketing,prosecution,filing_workflow,notification,audit}/`, `tests/test_{application_intake,application_intake_extended,application_fields,docketing,prosecution,prosecution_extended,lifecycle_transitions,flow_integration}.py`, `tests/edge/test_flow_edge.py`.

**Scope boundary (agreed with the human partner):** L3 is spec-strict. Document upload/download/list, request-document, the missing-documents worklist and the `warning` on grant are **L4**; analytics, the NIPO scheduler and mail delivery are **L5**. Journeys J1/J2/J4 from issue #67 therefore land in L4; J3 (rejection), J5 (expired token — already covered by L2) and J6 (second-user isolation) are covered here, plus the flow-edge cases that do not touch documents.

**Rulings baked into this plan** (deliberate divergences from the FastAPI reference, justified by the spec/CONTEXT.md, which are the binding authority):

- **Error shape and codes.** `{"message": ...}` (L2 convention), never the reference's `{"detail": ...}`. Successes are `200` (or the existing `204` on `/status`); no `201`. Laravel validation errors use the framework's `{"message", "errors"}` 422 body.
- **Unknown application is `404` everywhere** (`ApplicationNotFound`), including the defect-sheet routes where the reference returned `422`.
- **History is ownership-gated** (`403` for a non-owner user). The reference let any authenticated user read any application's history (an IDOR); CONTEXT.md invariant 6 forbids it. `403`, not `404`, to match the rest of the endpoint matrix.
- **No `GET /applications/{id}` and no `DELETE`.** Applications are never deleted (audit trail + referential integrity); a single-application read arrives with the L6 UI. "CRUD" in issue #67 means create/list/patch/history in this layer.
- **No HTTP route for `ProsecutionService::fileResponse`** in L3. It is implemented and tested through the domain interface only, matching the reference (which also has no route) and keeping L3 from inventing API surface.
- **`/status` keeps its L2 contract**: `new_status` + `reason` in the body, `204` out. The reference's `warning` field (grant without a document) is L4, since documents do not exist yet.
- **Office actions are audited** (`receive_office_action:{KIND}`) even though the reference skipped it — invariant 5 says every state change is traceable.
- **Notification recipients are deduplicated and blank addresses skipped**; the reference sent duplicates and wrote rows for blank emails.
- **String lengths are capped at the schema's 255** (`title`, `technology_area`, inventor fields), which the reference did not do (a longer value would have hit the DB).
- **Deadline and office-action routes require the application to exist** (`404`); the reference happily created orphan rows. The FK constraints make orphans impossible here anyway. (Consequence: `POST /applications`, filed/defect-sheet endpoints and the domain services all need resolvable applications.)

## Global Constraints

- Run all commands from `backend/`. Test runner: `php artisan test --compact <args>` (or `vendor/bin/pest`).
- TDD always: RED → GREEN, one vertical slice at a time, never refactor while RED (AGENTS.md).
- Tests assert behavior through public interfaces (HTTP routes, domain service public methods) — not private internals.
- Domain logic in `app/Domain/*`; controllers thin; **no business logic in controllers or Eloquent models**; models are persistence-only.
- **Deadline rows are written only from `app/Domain/Docketing/`** — `tests/Unit/DocketingSoleAuthorityTest.php` scans all of `app/` and fails otherwise.
- `LifecycleService` (L1) stays pure: it writes the application status + `status_history` row and nothing else. Notification/audit orchestration lives in the new `StatusChangeService`.
- Env prefix `BLITTODB_`; session (`web` guard) auth only — no bearer tokens.
- Roles are the lowercase enum values `user` / `director` / `md` (`App\Domain\Authorization\Role`); gates are `role:director,md` (staff) and `role:md` (user management).
- Status codes are part of the contract: guest `401`, wrong role `403`, validation/lifecycle/defect-sheet violations `422`, unknown application `404`, unknown office action / missing filing record `404`.
- Error bodies use the L2 convention `{"message": "..."}`; validation errors use Laravel's `{"message": ..., "errors": {...}}`. The reference's `{"detail": ...}` is **not** ported.
- Successes are `200` with the resource (or a bare `204` for the pre-existing status endpoint, whose contract L3 keeps).
- Conventional commits, prefix `feat(l3):` / `test(l3):` / `chore(l3):`.
- After code changes: `vendor/bin/pint --dirty --format agent`; at the end `graphify update .` from the repo root.
- PHP style (Boost `php` rules): constructor property promotion, explicit parameter + return types, curly braces always, PHPDoc array shapes, TitleCase enum keys.
- Laravel version APIs are frozen at 13.35 — do not assume other versions. No new Composer dependencies.
- Create test files with `php artisan make:test --pest <Name>` (project convention); the paths given in each task are where the command should land.
- Enum cases follow the existing L1 convention — SCREAMING_SNAKE names matching the column values (`DeadlineType::FILING = 'FILING'`), like `ApplicationStatus`/`DeadlineStatus`/`Ability` — rather than the generic TitleCase rule.
- There is **no `Application` factory** and none is added: tests build domain rows with `Application::query()->create([...])` and users with `User::factory()->{user,director,md}()`.

## Review Focus

The input classes most likely to bite a person using this software; the task named in parentheses owns the test that pins each one.

1. **PATCH body with no editable key, and unknown-application ordering.** `PATCH /applications/{id}` with `{"status":"granted"}` must be `422` (the reference does this) even though the app exists, while `{"title":"X"}` for a missing id must be `404` — the filter runs before the lookup (Task 5).
2. **Confidentiality on reads (invariant 6).** A `user` must never see another inventor's application in `GET /applications` nor read its history — the reference's history route had an IDOR; L3 gates it (Task 5).
3. **Auto-docket arithmetic.** An office action of kind `REJECTION`/`OBJECTION` dockets exactly one `RESPONSE` deadline at now + 90 days; `ALLOWANCE` dockets none (Task 9).
4. **Defect-sheet caps and lifecycle coupling.** `sheet_number` outside 1–3 → `422`; a repeated number → `422`; the status target `DEFECT_SHEET_{n}` still has to pass the transition table, so e.g. `DEFECT_SHEET_1 → DEFECT_SHEET_3` is an illegal `422` (Task 12).
5. **Notification fan-out and audit on every filing step.** Every filing step notifies *every distinct inventor* (co-inventors included) and writes its own audit row; a step that fails validation writes neither (Tasks 11–13).

---

### Task 1: Notification writer (`NotificationService`)

**Files:**
- Create: `backend/app/Domain/Notification/NotificationService.php`
- Test: `backend/tests/Feature/NotificationServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\{Application,Inventor,Notification}`, `App\Domain\Lifecycle\ApplicationStatus`.
- Produces: `NotificationService::send(string $recipientEmail, string $subject, string $body, string $kind = 'status_change'): Notification`, `->notifyStatusChange(Application $application, ApplicationStatus $status): int` (fans out, returns rows written), `->notifyInventors(Application $application, string $subject, string $body, string $kind = 'filing'): int`, and `->recipients(Application $application): list<string>`.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\Inventor;
use App\Models\Notification;

it('writes a status-change notification using the reference template', function () {
    $application = Application::query()->create([
        'title' => 'Solar Widget',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);

    (new NotificationService)->notifyStatusChange($application, ApplicationStatus::FILED);

    $notification = Notification::query()->sole();

    expect($notification->recipient_email)->toBe('ada@pdn.ac.lk')
        ->and($notification->subject)->toBe("Patent {$application->id} filed to NIPO")
        ->and($notification->body)->toBe("The patent application {$application->id} has been filed to NIPO. Ref: {$application->id}.")
        ->and($notification->kind)->toBe('status_change');
});

it('falls back to a generic template for statuses with no wording of their own', function () {
    $application = Application::query()->create([
        'title' => 'Defective',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);

    (new NotificationService)->notifyStatusChange($application, ApplicationStatus::ACKNOWLEDGED);

    $notification = Notification::query()->sole();

    expect($notification->subject)->toBe("Patent {$application->id} status changed")
        ->and($notification->body)->toBe("The patent application {$application->id} is now: ACKNOWLEDGED.");
});

it('notifies every distinct inventor and skips blanks', function () {
    $application = Application::query()->create([
        'title' => 'Joint work',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);

    foreach (['ada@pdn.ac.lk', 'bob@pdn.ac.lk', 'ada@pdn.ac.lk', '  '] as $email) {
        Inventor::query()->create([
            'application_id' => $application->id,
            'inventor_name' => 'Inventor',
            'inventor_email' => $email,
        ]);
    }

    $sent = (new NotificationService)->notifyInventors($application, 'Patent Filed', 'Filed.');

    expect($sent)->toBe(2)
        ->and(Notification::query()->orderBy('recipient_email')->pluck('recipient_email')->all())
        ->toBe(['ada@pdn.ac.lk', 'bob@pdn.ac.lk'])
        ->and(Notification::query()->first()->kind)->toBe('filing');
});

it('falls back to the denormalised inventor email when no inventor rows exist', function () {
    $application = Application::query()->create([
        'title' => 'Solo',
        'inventor_email' => 'solo@pdn.ac.lk',
    ]);

    expect((new NotificationService)->recipients($application))->toBe(['solo@pdn.ac.lk']);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/NotificationServiceTest.php`
Expected: FAIL — `Class "App\Domain\Notification\NotificationService" not found`.

- [x] **Step 3: Write minimal implementation**

`backend/app/Domain/Notification/NotificationService.php`:

```php
<?php

namespace App\Domain\Notification;

use App\Domain\Lifecycle\ApplicationStatus;
use App\Models\Application;
use App\Models\Inventor;
use App\Models\Notification;

/**
 * Notification writer — port of pre-laravel-final-state
 * `app/modules/notification/local/__init__.py`. Persists rows in the
 * `notifications` table; mail delivery and the NIPO scheduler arrive at L5.
 */
final class NotificationService
{
    /** @var array<string, string> */
    private const array STATUS_SUBJECTS = [
        'DRAFT' => 'Patent {ref} drafted',
        'FILED' => 'Patent {ref} filed to NIPO',
        'PUBLISHED' => 'Patent {ref} published',
        'EXAMINATION' => 'Patent {ref} under examination',
        'GRANTED' => 'Patent {ref} GRANTED — download ready',
        'REJECTED' => 'Patent {ref} update: rejected',
        'MAINTENANCE' => 'Patent {ref} in maintenance',
    ];

    /** @var array<string, string> */
    private const array STATUS_BODIES = [
        'DRAFT' => 'The patent application {ref} is now: DRAFT.',
        'FILED' => 'The patent application {ref} has been filed to NIPO. Ref: {ref}.',
        'PUBLISHED' => 'The patent application {ref} is now published.',
        'EXAMINATION' => 'The patent application {ref} is now: EXAMINATION. No action needed.',
        'GRANTED' => 'Good news — patent {ref} is now GRANTED. You can download the patent document from your BLITTO portal.',
        'REJECTED' => 'The patent application {ref} was rejected. Please contact BLITTO for next steps.',
        'MAINTENANCE' => 'The patent {ref} is now in maintenance. Watch for fee deadlines.',
    ];

    public function send(string $recipientEmail, string $subject, string $body, string $kind = 'status_change'): Notification
    {
        return Notification::query()->create([
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'body' => $body,
            'kind' => $kind,
        ]);
    }

    /**
     * Notify every inventor of an application about a status change.
     *
     * @return int the number of notifications written
     */
    public function notifyStatusChange(Application $application, ApplicationStatus $status): int
    {
        $subject = str_replace('{ref}', $application->id, self::STATUS_SUBJECTS[$status->value] ?? 'Patent {ref} status changed');
        $body = str_replace('{ref}', $application->id, self::STATUS_BODIES[$status->value] ?? 'The patent application {ref} is now: '.$status->value.'.');

        $sent = 0;

        foreach ($this->recipients($application) as $email) {
            $this->send($email, $subject, $body);
            $sent++;
        }

        return $sent;
    }

    /**
     * @return int the number of notifications written
     */
    public function notifyInventors(Application $application, string $subject, string $body, string $kind = 'filing'): int
    {
        $sent = 0;

        foreach ($this->recipients($application) as $email) {
            $this->send($email, $subject, $body, $kind);
            $sent++;
        }

        return $sent;
    }

    /**
     * The inventor table is the normalised source; the denormalised
     * `applications.inventor_email` is the fallback for rows created
     * without inventor records (seeds, direct model writes).
     *
     * @return list<string>
     */
    public function recipients(Application $application): array
    {
        $emails = Inventor::query()
            ->where('application_id', $application->id)
            ->pluck('inventor_email')
            ->all();

        if ($emails === []) {
            $emails = [$application->inventor_email];
        }

        $emails = array_filter($emails, static fn (string $email): bool => trim($email) !== '');

        return array_values(array_unique($emails));
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/NotificationServiceTest.php`
Expected: PASS (4 tests).

- [x] **Step 5: Commit**

```bash
git add backend/app/Domain/Notification/NotificationService.php backend/tests/Feature/NotificationServiceTest.php
git commit -m "feat(l3): add notification writer with reference templates"
```

---

### Task 2: Audit recorder

**Files:**
- Create: `backend/app/Domain/Audit/AuditRecorder.php`
- Test: `backend/tests/Feature/AuditRecorderTest.php`

**Interfaces:**
- Consumes: `App\Models\{Application,AuditLog,User}`.
- Produces: `AuditRecorder::record(string $action, ?User $actor = null, ?Application $application = null, ?string $ipAddress = null): AuditLog` and `->forApplication(Application $application): Collection<int, AuditLog>`.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Domain\Audit\AuditRecorder;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\User;

it('records an audit entry with actor, application and ip', function () {
    $application = Application::query()->create(['title' => 'Audited']);
    $director = User::factory()->director()->create();

    (new AuditRecorder)->record('create_application', actor: $director, application: $application, ipAddress: '10.0.0.7');

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('create_application')
        ->and($log->user_id)->toBe($director->id)
        ->and($log->application_id)->toBe($application->id)
        ->and($log->ip_address)->toBe('10.0.0.7')
        ->and($log->timestamp)->not->toBeNull();
});

it('records an audit entry with no actor or application', function () {
    (new AuditRecorder)->record('system_check');

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('system_check')
        ->and($log->user_id)->toBeNull()
        ->and($log->application_id)->toBeNull();
});

it('scopes audit entries to one application', function () {
    $first = Application::query()->create(['title' => 'First']);
    $second = Application::query()->create(['title' => 'Second']);
    $recorder = new AuditRecorder;

    $recorder->record('create_application', application: $first);
    $recorder->record('status_change:FILED', application: $second);

    expect($recorder->forApplication($first)->pluck('action')->all())->toBe(['create_application']);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AuditRecorderTest.php`
Expected: FAIL — `Class "App\Domain\Audit\AuditRecorder" not found`.

- [x] **Step 3: Write minimal implementation**

`backend/app/Domain/Audit/AuditRecorder.php`:

```php
<?php

namespace App\Domain\Audit;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Audit trail (CONTEXT.md invariant 5) — port of pre-laravel-final-state
 * `app/modules/audit/interface.py`. Every state change is traceable to an
 * actor, an application and a timestamp.
 */
final class AuditRecorder
{
    public function record(
        string $action,
        ?User $actor = null,
        ?Application $application = null,
        ?string $ipAddress = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'action' => $action,
            'user_id' => $actor?->id,
            'application_id' => $application?->id,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public function forApplication(Application $application): Collection
    {
        return AuditLog::query()
            ->where('application_id', $application->id)
            ->orderBy('timestamp')
            ->get();
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/AuditRecorderTest.php`
Expected: PASS (3 tests).

- [x] **Step 5: Commit**

```bash
git add backend/app/Domain/Audit/AuditRecorder.php backend/tests/Feature/AuditRecorderTest.php
git commit -m "feat(l3): add audit recorder for domain state changes"
```

---

### Task 3: Application intake service (`Disclosure`, create/update/list/history)

**Files:**
- Create: `backend/app/Domain/ApplicationIntake/ApplicationNotFound.php`
- Create: `backend/app/Domain/ApplicationIntake/Disclosure.php`
- Create: `backend/app/Domain/ApplicationIntake/ApplicationIntakeService.php`
- Test: `backend/tests/Feature/ApplicationIntakeServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\{Application,Inventor,StatusHistory,User}`, `App\Domain\Audit\AuditRecorder`, `App\Domain\Authorization\{ApplicationVisibility,EmailPolicy}`, `App\Domain\Lifecycle\{ApplicationStatus,LifecycleService}`.
- Produces: `ApplicationNotFound` (`RuntimeException`, public readonly `$applicationId`, message `Application {id} not found`); `Disclosure` with public `$title`, `$inventorName`, `$inventorEmail`, `$inventors`, `$technologyArea`, static `fromArray(array $data): self` and `resolvedInventors(): list<array{inventor_name: string, inventor_email: string}>`; `ApplicationIntakeService::findOrFail(string $applicationId): Application`, `->createShell(Disclosure $disclosure, User $actor, ?string $ipAddress = null): Application`, `->updateFields(Application $application, ?string $title, ?string $technologyArea): Application`, `->listVisibleTo(User $viewer): Collection<int, Application>`, `->history(Application $application): Collection<int, StatusHistory>`.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\ApplicationIntake\ApplicationNotFound;
use App\Domain\ApplicationIntake\Disclosure;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\LifecycleService;
use App\Models\Application;
use App\Models\Inventor;
use App\Models\User;

it('creates a draft application shell and denormalises the first inventor', function () {
    $director = User::factory()->director()->create();

    $application = app(ApplicationIntakeService::class)->createShell(
        new Disclosure(
            title: 'Solar Widget',
            inventorName: 'A. Inventor',
            inventorEmail: 'ada@pdn.ac.lk',
            technologyArea: 'Solar',
        ),
        $director,
    );

    expect($application->status)->toBe('DRAFT')
        ->and($application->title)->toBe('Solar Widget')
        ->and($application->inventor_name)->toBe('A. Inventor')
        ->and($application->inventor_email)->toBe('ada@pdn.ac.lk')
        ->and($application->technology_area)->toBe('Solar')
        ->and($application->application_number)->toBeNull()
        ->and($application->nipo_reference)->toBeNull()
        ->and(Inventor::query()->count())->toBe(1);
});

it('uses the inventors list as the source of truth and normalises their emails', function () {
    $director = User::factory()->director()->create();

    $application = app(ApplicationIntakeService::class)->createShell(
        Disclosure::fromArray([
            'title' => 'Joint Patent',
            'inventors' => [
                ['inventor_name' => 'Alice', 'inventor_email' => 'ALICE@pdn.ac.lk '],
                ['inventor_name' => 'Bob', 'inventor_email' => 'bob@pdn.ac.lk'],
            ],
        ]),
        $director,
    );

    expect($application->inventor_name)->toBe('Alice')
        ->and($application->inventor_email)->toBe('alice@pdn.ac.lk')
        ->and(Inventor::query()->count())->toBe(2);
});

it('writes a create_application audit entry', function () {
    $director = User::factory()->director()->create();

    $application = app(ApplicationIntakeService::class)->createShell(
        new Disclosure(title: 'Audited', inventorName: 'A', inventorEmail: 'a@pdn.ac.lk'),
        $director,
        ipAddress: '10.0.0.9',
    );

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'create_application',
        'user_id' => $director->id,
        'application_id' => $application->id,
        'ip_address' => '10.0.0.9',
    ]);
});

it('updates only the editable fields', function () {
    $application = Application::query()->create(['title' => 'Old', 'technology_area' => 'Old Tech']);

    app(ApplicationIntakeService::class)->updateFields($application, 'New', 'New Tech');

    expect($application->refresh()->title)->toBe('New')
        ->and($application->technology_area)->toBe('New Tech')
        ->and($application->status)->toBe('DRAFT');
});

it('leaves a field untouched when null is passed', function () {
    $application = Application::query()->create(['title' => 'Keep', 'technology_area' => 'Keep Tech']);

    app(ApplicationIntakeService::class)->updateFields($application, null, 'Only Tech');

    expect($application->refresh()->title)->toBe('Keep')
        ->and($application->technology_area)->toBe('Only Tech');
});

it('lists every application for staff and only own applications for a user', function () {
    $service = app(ApplicationIntakeService::class);
    Application::query()->create(['title' => 'A', 'inventor_email' => 'ada@pdn.ac.lk']);
    Application::query()->create(['title' => 'B', 'inventor_email' => 'bob@pdn.ac.lk']);

    $user = User::factory()->create(['email' => 'ada@pdn.ac.lk']);
    $director = User::factory()->director()->create();

    $visible = $service->listVisibleTo($user);

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->title)->toBe('A')
        ->and($service->listVisibleTo($director))->toHaveCount(2);
});

it('returns the status history in change order', function () {
    $application = Application::query()->create(['title' => 'Trail']);
    $lifecycle = new LifecycleService;

    $lifecycle->changeStatus($application, ApplicationStatus::FILED, changedBy: 'director@pdn.ac.lk', reason: 'Filed');
    $this->travel(1)->minutes();
    $lifecycle->changeStatus($application, ApplicationStatus::EXAMINATION, changedBy: 'md@pdn.ac.lk', reason: 'Examined');

    expect(app(ApplicationIntakeService::class)->history($application)->pluck('new_status')->all())
        ->toBe(['FILED', 'EXAMINATION']);
});

it('throws a not-found exception for an unknown application', function () {
    app(ApplicationIntakeService::class)->findOrFail('missing-id');
})->throws(ApplicationNotFound::class, 'Application missing-id not found');
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/ApplicationIntakeServiceTest.php`
Expected: FAIL — `Class "App\Domain\ApplicationIntake\ApplicationIntakeService" not found`.

- [x] **Step 3: Write minimal implementation**

`backend/app/Domain/ApplicationIntake/ApplicationNotFound.php`:

```php
<?php

namespace App\Domain\ApplicationIntake;

use RuntimeException;

/**
 * Raised when an application id does not resolve to a row. Mapped to
 * HTTP 404 by the API layer (bootstrap/app.php).
 */
final class ApplicationNotFound extends RuntimeException
{
    public function __construct(public readonly string $applicationId)
    {
        parent::__construct("Application {$applicationId} not found");
    }
}
```

`backend/app/Domain/ApplicationIntake/Disclosure.php`:

```php
<?php

namespace App\Domain\ApplicationIntake;

use App\Domain\Authorization\EmailPolicy;

/**
 * The intake payload — port of the FastAPI `Disclosure` model
 * (`app/modules/application_intake/models.py`), minus the Pydantic
 * validators that the controller now expresses as Laravel rules.
 */
final class Disclosure
{
    /**
     * @param  list<array{inventor_name: string, inventor_email: string}>  $inventors
     */
    public function __construct(
        public string $title,
        public string $inventorName = '',
        public string $inventorEmail = '',
        public array $inventors = [],
        public string $technologyArea = '',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array{inventor_name: string, inventor_email: string}> $inventors */
        $inventors = array_values(array_map(
            static fn (array $inventor): array => [
                'inventor_name' => trim((string) $inventor['inventor_name']),
                'inventor_email' => EmailPolicy::normalize((string) $inventor['inventor_email']),
            ],
            $data['inventors'] ?? [],
        ));

        return new self(
            title: trim((string) $data['title']),
            inventorName: trim((string) ($data['inventor_name'] ?? '')),
            inventorEmail: EmailPolicy::normalize((string) ($data['inventor_email'] ?? '')),
            inventors: $inventors,
            technologyArea: trim((string) ($data['technology_area'] ?? '')),
        );
    }

    /**
     * One application has one main author for display purposes; the
     * `inventors` list is the multi-inventor source of truth.
     *
     * @return list<array{inventor_name: string, inventor_email: string}>
     */
    public function resolvedInventors(): array
    {
        if ($this->inventors !== []) {
            return $this->inventors;
        }

        return [[
            'inventor_name' => $this->inventorName,
            'inventor_email' => $this->inventorEmail,
        ]];
    }
}
```

`backend/app/Domain/ApplicationIntake/ApplicationIntakeService.php`:

```php
<?php

namespace App\Domain\ApplicationIntake;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Authorization\ApplicationVisibility;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Models\Application;
use App\Models\Inventor;
use App\Models\StatusHistory;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Application intake — port of pre-laravel-final-state
 * `app/modules/application_intake/local/__init__.py`. Owns the application
 * aggregate: shell creation, field edits, visibility-scoped listing and the
 * status trail. Status *transitions* belong to the lifecycle domain.
 */
final class ApplicationIntakeService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @throws ApplicationNotFound
     */
    public function findOrFail(string $applicationId): Application
    {
        return Application::query()->find($applicationId)
            ?? throw new ApplicationNotFound($applicationId);
    }

    public function createShell(Disclosure $disclosure, User $actor, ?string $ipAddress = null): Application
    {
        $inventors = $disclosure->resolvedInventors();
        $main = $inventors[0];

        $application = Application::query()->create([
            'title' => $disclosure->title,
            'inventor_name' => $main['inventor_name'],
            'inventor_email' => $main['inventor_email'],
            'technology_area' => $disclosure->technologyArea,
            'status' => ApplicationStatus::DRAFT->value,
        ]);

        foreach ($inventors as $inventor) {
            Inventor::query()->create([
                'application_id' => $application->id,
                'inventor_name' => $inventor['inventor_name'],
                'inventor_email' => $inventor['inventor_email'],
            ]);
        }

        $this->audit->record('create_application', actor: $actor, application: $application, ipAddress: $ipAddress);

        return $application;
    }

    public function updateFields(Application $application, ?string $title, ?string $technologyArea): Application
    {
        if ($title !== null) {
            $application->title = $title;
        }

        if ($technologyArea !== null) {
            $application->technology_area = $technologyArea;
        }

        $application->save();

        return $application;
    }

    /**
     * @return Collection<int, Application>
     */
    public function listVisibleTo(User $viewer): Collection
    {
        $query = Application::query()->orderBy('created_at')->orderBy('id');

        return ApplicationVisibility::visibleTo($query, $viewer)->get();
    }

    /**
     * @return Collection<int, StatusHistory>
     */
    public function history(Application $application): Collection
    {
        return StatusHistory::query()
            ->where('application_id', $application->id)
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get();
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/ApplicationIntakeServiceTest.php`
Expected: PASS (8 tests).

- [x] **Step 5: Commit**

```bash
git add backend/app/Domain/ApplicationIntake backend/tests/Feature/ApplicationIntakeServiceTest.php
git commit -m "feat(l3): add application intake domain service"
```

---

### Task 4: `POST /applications` (staff-only create)

**Files:**
- Create: `backend/app/Http/Controllers/ApplicationController.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/ApplicationCreateTest.php`

**Interfaces:**
- Consumes: `ApplicationIntakeService`, `Disclosure`, `EmailPolicy`, routes/middleware `auth` + `role:director,md`.
- Produces: `ApplicationController::store(Request): JsonResponse` on `POST /applications` (name `applications.store`), 200 with the application JSON.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Models\Application;
use App\Models\User;

it('creates an application as staff', function (string $state) {
    $staff = User::factory()->{$state}()->create(['email' => "{$state}@pdn.ac.lk"]);

    $response = $this->actingAs($staff)->postJson('/applications', [
        'title' => 'Solar Desalination Membrane',
        'inventor_name' => 'Dr. Ada Perera',
        'inventor_email' => 'user-a@pdn.ac.lk',
        'summary' => 'A low-cost membrane for solar-powered desalination.',
        'technology_area' => 'Materials',
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'DRAFT')
        ->assertJsonPath('title', 'Solar Desalination Membrane')
        ->assertJsonPath('inventor_email', 'user-a@pdn.ac.lk')
        ->assertJsonPath('technology_area', 'Materials')
        ->assertJsonPath('application_number', null)
        ->assertJsonPath('nipo_reference', null);

    $this->assertDatabaseHas('applications', ['title' => 'Solar Desalination Membrane', 'status' => 'DRAFT']);
    $this->assertDatabaseHas('inventors', ['inventor_email' => 'user-a@pdn.ac.lk', 'inventor_name' => 'Dr. Ada Perera']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'create_application', 'user_id' => $staff->id]);
})->with(['director', 'md']);

it('normalises the inventor email before storing it', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson('/applications', [
        'title' => 'Normalised',
        'inventor_name' => 'Ada',
        'inventor_email' => '  ADA@PDN.AC.LK ',
    ])->assertOk()->assertJsonPath('inventor_email', 'ada@pdn.ac.lk');
});

it('rejects a foreign inventor domain', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson('/applications', [
        'title' => 'Foreign',
        'inventor_name' => 'Ada',
        'inventor_email' => 'ada@gmail.com',
    ])->assertStatus(422)->assertJsonValidationErrors('inventor_email');
});

it('rejects lookalike institution domains', function (string $email) {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson('/applications', [
        'title' => 'Lookalike',
        'inventor_name' => 'Ada',
        'inventor_email' => $email,
    ])->assertStatus(422)->assertJsonValidationErrors('inventor_email');
})->with(['ada@pdn.ac.lk.evil.com', 'ada@fakepdn.ac.lk']);

it('rejects multi-author payloads', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson('/applications', [
        'title' => 'Lists',
        'inventor_name' => 'A, B',
        'inventor_email' => 'a@pdn.ac.lk',
    ])->assertStatus(422)->assertJsonValidationErrors('inventor_name');

    $this->actingAs($director)->postJson('/applications', [
        'title' => 'Lists',
        'inventor_name' => 'A',
        'inventor_email' => 'a@pdn.ac.lk; b@pdn.ac.lk',
    ])->assertStatus(422)->assertJsonValidationErrors('inventor_email');
});

it('rejects a blank inventor name and a missing title', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson('/applications', [
        'title' => 'Blank',
        'inventor_name' => '   ',
        'inventor_email' => 'a@pdn.ac.lk',
    ])->assertStatus(422)->assertJsonValidationErrors('inventor_name');

    $this->actingAs($director)->postJson('/applications', [
        'inventor_name' => 'A',
        'inventor_email' => 'a@pdn.ac.lk',
    ])->assertStatus(422)->assertJsonValidationErrors('title');
});

it('requires either the top-level inventor fields or an inventors list', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson('/applications', ['title' => 'No inventor'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['inventor_name', 'inventor_email']);
});

it('creates from an inventors list when the top-level fields are omitted', function () {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director)->postJson('/applications', [
        'title' => 'Joint Patent',
        'inventors' => [
            ['inventor_name' => 'Alice', 'inventor_email' => 'alice@pdn.ac.lk'],
            ['inventor_name' => 'Bob', 'inventor_email' => 'bob@pdn.ac.lk'],
        ],
    ]);

    $response->assertOk()->assertJsonPath('inventor_name', 'Alice');
    $this->assertDatabaseHas('inventors', ['inventor_email' => 'bob@pdn.ac.lk']);
});

it('gates application creation to staff', function () {
    $payload = ['title' => 'Gated', 'inventor_name' => 'A', 'inventor_email' => 'a@pdn.ac.lk'];

    $this->postJson('/applications', $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson('/applications', $payload)->assertForbidden();
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/ApplicationCreateTest.php`
Expected: FAIL with 404 on `POST /applications` (route not defined).

- [x] **Step 3: Write minimal implementation**

`backend/app/Http/Controllers/ApplicationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\ApplicationIntake\Disclosure;
use App\Domain\Authorization\EmailPolicy;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller: validates input, delegates to the intake domain.
 * Domain rules live in App\Domain\ApplicationIntake.
 */
final class ApplicationController extends Controller
{
    public function __construct(private readonly ApplicationIntakeService $intake) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->createRules(), [
            'inventor_name.not_regex' => 'One main author only: single inventor_name, no lists',
            'inventor_email.not_regex' => 'One main author only: single inventor_email, no lists',
        ]);

        return response()->json($this->intake->createShell(
            Disclosure::fromArray($validated),
            $request->user(),
            $request->ip(),
        ));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function createRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'inventor_name' => ['required_without:inventors', 'string', 'max:255', 'not_regex:/[,;]/'],
            'inventor_email' => [
                'required_without:inventors',
                'string',
                'max:255',
                'email',
                'not_regex:/[,;]/',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! EmailPolicy::isInstitution((string) $value)) {
                        $fail(EmailPolicy::error());
                    }
                },
            ],
            'inventors' => ['sometimes', 'array'],
            'inventors.*.inventor_name' => ['required', 'string', 'max:255'],
            'inventors.*.inventor_email' => ['required', 'string', 'email', 'max:255'],
            'technology_area' => ['sometimes', 'string', 'max:255'],
            'summary' => ['sometimes', 'string'],
        ];
    }
}
```

`backend/routes/web.php` — add above the existing status route:

```php
Route::post('/applications', [ApplicationController::class, 'store'])
    ->middleware(['auth', 'role:director,md'])
    ->name('applications.store');
```

and the matching `use App\Http\Controllers\ApplicationController;` import.

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/ApplicationCreateTest.php`
Expected: PASS (8 tests).

- [x] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/ApplicationController.php backend/routes/web.php backend/tests/Feature/ApplicationCreateTest.php
git commit -m "feat(l3): add staff-only application creation endpoint"
```

---

### Task 5: `GET /applications`, `PATCH /applications/{id}`, history (+ `ApplicationNotFound` → 404)

**Files:**
- Modify: `backend/app/Http/Controllers/ApplicationController.php`
- Modify: `backend/routes/web.php`
- Modify: `backend/bootstrap/app.php`
- Test: `backend/tests/Feature/ApplicationReadUpdateTest.php`

**Interfaces:**
- Consumes: `ApplicationIntakeService`, `ApplicationNotFound`, `App\Domain\Authorization\Role`, routes/middleware `auth` and `auth` + `role:director,md`.
- Produces: `ApplicationController::index(Request): JsonResponse` (`GET /applications`), `->update(Request, string $applicationId): JsonResponse` (`PATCH /applications/{applicationId}`), `->history(Request, string $applicationId): JsonResponse` (`GET /applications/{applicationId}/history`); `ApplicationNotFound` renders as 404 `{"message": "Application {id} not found"}`.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\LifecycleService;
use App\Models\Application;
use App\Models\User;

it('shows a user only their own applications', function () {
    $owner = User::factory()->create(['email' => 'ada@pdn.ac.lk']);
    $other = User::factory()->create(['email' => 'bob@pdn.ac.lk']);
    $mine = Application::query()->create(['title' => 'Mine', 'inventor_email' => 'ada@pdn.ac.lk']);
    Application::query()->create(['title' => 'Theirs', 'inventor_email' => 'bob@pdn.ac.lk']);

    $response = $this->actingAs($owner)->getJson('/applications');

    $response->assertOk();
    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($mine->id)
        ->and($this->actingAs($other)->getJson('/applications')->json())->toHaveCount(1);
});

it('shows every application to staff', function () {
    $director = User::factory()->director()->create();
    Application::query()->create(['title' => 'A', 'inventor_email' => 'a@pdn.ac.lk']);
    Application::query()->create(['title' => 'B', 'inventor_email' => 'b@pdn.ac.lk']);

    expect($this->actingAs($director)->getJson('/applications')->assertOk()->json())->toHaveCount(2);
});

it('gates the application list to authenticated users', function () {
    $this->getJson('/applications')->assertUnauthorized();
});

it('updates the editable fields as staff', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Old', 'technology_area' => 'Old Tech']);

    $this->actingAs($director)->patchJson("/applications/{$application->id}", [
        'title' => 'Renamed',
        'technology_area' => 'Bio',
    ])->assertOk()
        ->assertJsonPath('title', 'Renamed')
        ->assertJsonPath('technology_area', 'Bio')
        ->assertJsonPath('status', 'DRAFT');

    $this->assertDatabaseHas('applications', ['id' => $application->id, 'title' => 'Renamed', 'status' => 'DRAFT']);
});

it('rejects a patch that carries no editable field', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Fixed']);

    $this->actingAs($director)->patchJson("/applications/{$application->id}", ['status' => 'granted'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'No editable fields provided');
});

it('returns 404 for a patch against an unknown application', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->patchJson('/applications/missing-id', ['title' => 'X'])
        ->assertNotFound()
        ->assertJsonPath('message', 'Application missing-id not found');
});

it('gates patching to staff', function () {
    $application = Application::query()->create(['title' => 'Gated']);
    $payload = ['title' => 'Nope'];

    $this->patchJson("/applications/{$application->id}", $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->create())
        ->patchJson("/applications/{$application->id}", $payload)->assertForbidden();
});

it('returns the status history of an application to its inventor', function () {
    $owner = User::factory()->create(['email' => 'ada@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Trail', 'inventor_email' => 'ada@pdn.ac.lk']);

    (new LifecycleService)->changeStatus($application, ApplicationStatus::FILED, changedBy: 'director@pdn.ac.lk', reason: 'Filed');

    $this->actingAs($owner)->getJson("/applications/{$application->id}/history")
        ->assertOk()
        ->assertJsonPath('0.new_status', 'FILED')
        ->assertJsonPath('0.old_status', 'DRAFT')
        ->assertJsonPath('0.changed_by', 'director@pdn.ac.lk')
        ->assertJsonPath('0.reason', 'Filed');
});

it('refuses history for another inventor application', function () {
    $other = User::factory()->create(['email' => 'bob@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Not mine', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($other)->getJson("/applications/{$application->id}/history")->assertForbidden();
});

it('lets staff read any history and returns 404 for unknown applications', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Any', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($director)->getJson("/applications/{$application->id}/history")->assertOk();
    $this->actingAs($director)->getJson('/applications/missing-id/history')->assertNotFound();
});

it('gates history to authenticated users', function () {
    $application = Application::query()->create(['title' => 'Gated']);

    $this->getJson("/applications/{$application->id}/history")->assertUnauthorized();
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/ApplicationReadUpdateTest.php`
Expected: FAIL with 404 on `GET /applications` (route not defined).

- [x] **Step 3: Write minimal implementation**

`backend/app/Http/Controllers/ApplicationController.php` (full final file):

```php
<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\ApplicationIntake\Disclosure;
use App\Domain\Authorization\EmailPolicy;
use App\Domain\Authorization\Role;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller: validates input, delegates to the intake domain.
 * Domain rules live in App\Domain\ApplicationIntake.
 */
final class ApplicationController extends Controller
{
    public function __construct(private readonly ApplicationIntakeService $intake) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->intake->listVisibleTo($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->createRules(), [
            'inventor_name.not_regex' => 'One main author only: single inventor_name, no lists',
            'inventor_email.not_regex' => 'One main author only: single inventor_email, no lists',
        ]);

        return response()->json($this->intake->createShell(
            Disclosure::fromArray($validated),
            $request->user(),
            $request->ip(),
        ));
    }

    public function update(Request $request, string $applicationId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'technology_area' => ['sometimes', 'string', 'max:255'],
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'No editable fields provided'], 422);
        }

        return response()->json($this->intake->updateFields(
            $this->intake->findOrFail($applicationId),
            $validated['title'] ?? null,
            $validated['technology_area'] ?? null,
        ));
    }

    public function history(Request $request, string $applicationId): JsonResponse
    {
        $application = $this->intake->findOrFail($applicationId);
        $viewer = $request->user();

        // Confidentiality (CONTEXT.md invariant 6): a user sees only their own.
        if (Role::from($viewer->role) === Role::USER && $application->inventor_email !== $viewer->email) {
            abort(403, 'Forbidden.');
        }

        return response()->json($this->intake->history($application));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function createRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'inventor_name' => ['required_without:inventors', 'string', 'max:255', 'not_regex:/[,;]/'],
            'inventor_email' => [
                'required_without:inventors',
                'string',
                'max:255',
                'email',
                'not_regex:/[,;]/',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! EmailPolicy::isInstitution((string) $value)) {
                        $fail(EmailPolicy::error());
                    }
                },
            ],
            'inventors' => ['sometimes', 'array'],
            'inventors.*.inventor_name' => ['required', 'string', 'max:255'],
            'inventors.*.inventor_email' => ['required', 'string', 'email', 'max:255'],
            'technology_area' => ['sometimes', 'string', 'max:255'],
            'summary' => ['sometimes', 'string'],
        ];
    }
}
```

`backend/routes/web.php` — add next to the create route:

```php
Route::get('/applications', [ApplicationController::class, 'index'])
    ->middleware('auth')
    ->name('applications.index');

Route::patch('/applications/{applicationId}', [ApplicationController::class, 'update'])
    ->middleware(['auth', 'role:director,md'])
    ->name('applications.update');

Route::get('/applications/{applicationId}/history', [ApplicationController::class, 'history'])
    ->middleware('auth')
    ->name('applications.history');
```

`backend/bootstrap/app.php` — inside `withExceptions`, beside the other renders:

```php
$exceptions->render(
    fn (ApplicationNotFound $e) => response()->json([
        'message' => $e->getMessage(),
    ], 404),
);
```

and the matching `use App\Domain\ApplicationIntake\ApplicationNotFound;` import.

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/ApplicationReadUpdateTest.php`
Expected: PASS (12 tests).

- [x] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/ApplicationController.php backend/routes/web.php backend/bootstrap/app.php backend/tests/Feature/ApplicationReadUpdateTest.php
git commit -m "feat(l3): add application list, patch and history with own-only visibility"
```

---

### Task 6: Status changes notify + audit (`StatusChangeService`)

**Files:**
- Create: `backend/app/Domain/Lifecycle/StatusChangeService.php`
- Modify: `backend/app/Http/Controllers/ApplicationStatusController.php`
- Modify: `backend/tests/Feature/StatusEndpointTest.php` (add the enum-validation case)
- Test: `backend/tests/Feature/StatusChangeTest.php`

**Interfaces:**
- Consumes: `App\Domain\Lifecycle\LifecycleService` (stays pure), `App\Domain\Notification\NotificationService::notifyStatusChange()`, `App\Domain\Audit\AuditRecorder::record()`.
- Produces: `StatusChangeService::change(Application $application, ApplicationStatus $newStatus, string $reason, User $actor, ?string $ipAddress = null): Application`; `POST /applications/{application}/status` keeps its body contract (`new_status`, `reason`) and its `204`, but now rejects unknown statuses with `422` and notifies + audits.

- [x] **Step 1: Write the failing test**

`backend/tests/Feature/StatusChangeTest.php`:

```php
<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Inventor;
use App\Models\Notification;
use App\Models\User;

it('notifies every inventor and audits the change', function () {
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Filed', 'inventor_email' => 'ada@pdn.ac.lk']);

    foreach (['ada@pdn.ac.lk', 'bob@pdn.ac.lk'] as $email) {
        Inventor::query()->create([
            'application_id' => $application->id,
            'inventor_name' => 'Inventor',
            'inventor_email' => $email,
        ]);
    }

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'FILED',
        'reason' => 'NIPO receipt recorded',
    ])->assertNoContent();

    expect($application->refresh()->status)->toBe('FILED')
        ->and(Notification::query()->count())->toBe(2)
        ->and(Notification::query()->pluck('recipient_email')->sort()->values()->all())
        ->toBe(['ada@pdn.ac.lk', 'bob@pdn.ac.lk'])
        ->and(Notification::query()->first()->subject)->toBe("Patent {$application->id} filed to NIPO")
        ->and(AuditLog::query()->where('action', 'status_change:FILED')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'status_change:FILED')->sole()->user_id)->toBe($director->id);
});

it('rejects an unknown status with 422 instead of a server error', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Bogus']);

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'SOMEDAY',
        'reason' => 'typo',
    ])->assertStatus(422)->assertJsonValidationErrors('new_status');

    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});

it('writes no notification or audit row when the transition is illegal', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Jump', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'GRANTED',
        'reason' => 'too early',
    ])->assertStatus(422);

    expect($application->refresh()->status)->toBe('DRAFT');
    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});

it('audits the actor email in the status history', function () {
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Trail']);

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'FILED',
        'reason' => 'Filed receipt recorded',
    ])->assertNoContent();

    $this->assertDatabaseHas('status_history', [
        'application_id' => $application->id,
        'old_status' => 'DRAFT',
        'new_status' => 'FILED',
        'changed_by' => 'director@pdn.ac.lk',
        'reason' => 'Filed receipt recorded',
    ]);
});
```

`backend/tests/Feature/StatusEndpointTest.php` — add:

```php
it('rejects an unknown status with 422', function () {
    $application = Application::query()->create(['title' => 'Bogus']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'SOMEDAY',
        'reason' => 'typo',
    ])->assertStatus(422);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/StatusChangeTest.php tests/Feature/StatusEndpointTest.php`
Expected: FAIL — the first test finds 0 notifications (only history is written today); the unknown-status test gets a 500, not a 422.

- [x] **Step 3: Write minimal implementation**

`backend/app/Domain/Lifecycle/StatusChangeService.php`:

```php
<?php

namespace App\Domain\Lifecycle;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\User;

/**
 * Orchestrates a status change: the pure LifecycleService owns the
 * transition table and the status_history row; this service adds the two
 * cross-cutting obligations from CONTEXT.md — notify the inventors and
 * record the audit trail (invariants 5 and 6).
 */
final class StatusChangeService
{
    public function __construct(
        private readonly LifecycleService $lifecycle,
        private readonly NotificationService $notifications,
        private readonly AuditRecorder $audit,
    ) {}

    public function change(
        Application $application,
        ApplicationStatus $newStatus,
        string $reason,
        User $actor,
        ?string $ipAddress = null,
    ): Application {
        $this->lifecycle->changeStatus(
            $application,
            $newStatus,
            changedBy: (string) $actor->email,
            reason: $reason,
        );

        $this->audit->record(
            'status_change:'.$newStatus->value,
            actor: $actor,
            application: $application,
            ipAddress: $ipAddress,
        );

        $this->notifications->notifyStatusChange($application, $newStatus);

        return $application;
    }
}
```

`backend/app/Http/Controllers/ApplicationStatusController.php` (full final file):

```php
<?php

namespace App\Http\Controllers;

use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\StatusChangeService;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Thin controller: parses input, delegates to the lifecycle domain, returns 204.
 * Domain rules (transition table, notification, audit) live in App\Domain\Lifecycle.
 */
class ApplicationStatusController extends Controller
{
    public function __construct(private readonly StatusChangeService $statusChange) {}

    public function store(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'new_status' => ['required', Rule::enum(ApplicationStatus::class)],
            'reason' => ['required', 'string', 'min:1', 'not_regex:/^\s*$/'],
        ]);

        $this->statusChange->change(
            $application,
            ApplicationStatus::from($validated['new_status']),
            reason: (string) $validated['reason'],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );

        return response()->json(null, 204);
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/StatusChangeTest.php tests/Feature/StatusEndpointTest.php`
Expected: PASS (4 + 6 tests).

- [x] **Step 5: Commit**

```bash
git add backend/app/Domain/Lifecycle/StatusChangeService.php backend/app/Http/Controllers/ApplicationStatusController.php backend/tests/Feature/StatusChangeTest.php backend/tests/Feature/StatusEndpointTest.php
git commit -m "feat(l3): notify inventors and audit on status change"
```

---

### Task 7: Docketing domain — `DeadlineType`, add + list deadlines

**Files:**
- Create: `backend/app/Domain/Docketing/DeadlineType.php`
- Modify: `backend/app/Domain/Docketing/DocketingService.php`
- Modify: `backend/app/Models/Deadline.php` (add the `due_date` date cast)
- Modify: `backend/tests/Feature/DocketingServiceTest.php` (construction only)
- Test: `backend/tests/Feature/DocketingDomainTest.php`

**Interfaces:**
- Consumes: `App\Models\{Application,Deadline,User}`, `App\Domain\Audit\AuditRecorder`.
- Produces: `DeadlineType` enum (`FILING`/`RESPONSE`/`MAINTENANCE_FEE`, values identical to the case names); `DocketingService::__construct(AuditRecorder $audit)`, `->addDeadline(Application $application, DeadlineType $type, CarbonInterface $dueDate, User $actor, ?string $ipAddress = null): Deadline`, `->listDeadlines(?string $applicationId = null): Collection<int, Deadline>`, and the unchanged `->markMet(Deadline $deadline): Deadline`.

> **Ruling:** `DocketingService` gains an `AuditRecorder` dependency because invariant 5 (audit trail) belongs to the domain service that owns the mutation, not to the controller. The only legacy construction sites are two `new DocketingService` calls in `tests/Feature/DocketingServiceTest.php`, which this task rewrites to `app(DocketingService::class)`.

- [x] **Step 1: Write the failing test**

`backend/tests/Feature/DocketingDomainTest.php`:

```php
<?php

use App\Domain\Docketing\DeadlineType;
use App\Domain\Docketing\DocketingService;
use App\Models\Application;
use App\Models\Deadline;
use App\Models\User;
use Illuminate\Support\Carbon;

it('adds an open deadline and audits it', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Docketed']);
    $due = Carbon::parse('2026-12-31T00:00:00Z');

    $deadline = app(DocketingService::class)->addDeadline($application, DeadlineType::FILING, $due, $director);

    expect($deadline->status)->toBe('OPEN')
        ->and($deadline->type)->toBe('FILING')
        ->and($deadline->application_id)->toBe($application->id)
        ->and($deadline->due_date->toIso8601String())->toBe($due->toIso8601String());

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'add_deadline:FILING',
        'user_id' => $director->id,
        'application_id' => $application->id,
    ]);
});

it('accepts every deadline type', function (DeadlineType $type) {
    $application = Application::query()->create(['title' => 'Typed']);

    $deadline = app(DocketingService::class)->addDeadline(
        $application,
        $type,
        Carbon::parse('2026-06-01T00:00:00Z'),
        User::factory()->director()->create(),
    );

    expect($deadline->refresh()->type)->toBe($type->value);
})->with(DeadlineType::cases());

it('lists all deadlines or only one application deadlines', function () {
    $first = Application::query()->create(['title' => 'First']);
    $second = Application::query()->create(['title' => 'Second']);
    $service = app(DocketingService::class);
    $actor = User::factory()->director()->create();
    $due = Carbon::parse('2026-01-01T00:00:00Z');

    $service->addDeadline($first, DeadlineType::FILING, $due, $actor);
    $service->addDeadline($second, DeadlineType::RESPONSE, $due, $actor);

    expect($service->listDeadlines())->toHaveCount(2)
        ->and($service->listDeadlines($first->id))->toHaveCount(1)
        ->and($service->listDeadlines($first->id)->first()->application_id)->toBe($first->id)
        ->and($service->listDeadlines('missing-id'))->toBeEmpty();
});

it('allows several deadlines of the same type on one application', function () {
    $application = Application::query()->create(['title' => 'Many']);
    $service = app(DocketingService::class);
    $actor = User::factory()->director()->create();

    $service->addDeadline($application, DeadlineType::RESPONSE, Carbon::parse('2026-06-01T00:00:00Z'), $actor);
    $service->addDeadline($application, DeadlineType::RESPONSE, Carbon::parse('2026-07-01T00:00:00Z'), $actor);

    expect(Deadline::query()->where('application_id', $application->id)->count())->toBe(2);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/DocketingDomainTest.php`
Expected: FAIL — `Class "App\Domain\Docketing\DeadlineType" not found`.

- [x] **Step 3: Write minimal implementation**

`backend/app/Domain/Docketing/DeadlineType.php`:

```php
<?php

namespace App\Domain\Docketing;

/**
 * Deadline categories (CONTEXT.md §Domain Glossary). Values match the
 * `deadlines.type` column defined in the L0 schema.
 */
enum DeadlineType: string
{
    case FILING = 'FILING';
    case RESPONSE = 'RESPONSE';
    case MAINTENANCE_FEE = 'MAINTENANCE_FEE';
}
```

`backend/app/Models/Deadline.php` — add the date cast (the L1 model casts nothing, so `due_date` would otherwise hydrate as a raw string):

```php
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'due_date' => 'datetime',
    ];
```

`backend/app/Domain/Docketing/DocketingService.php`:

```php
<?php

namespace App\Domain\Docketing;

use App\Domain\Audit\AuditRecorder;
use App\Models\Application;
use App\Models\Deadline;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Docketing domain rules — the sole authority on deadlines (CONTEXT.md
 * invariant 4) and deadline integrity (invariant 1). Port of
 * pre-laravel-final-state `app/modules/docketing/local/`.
 */
class DocketingService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @throws IllegalDeadlineChange when the deadline is already MISSED
     */
    public function markMet(Deadline $deadline): Deadline
    {
        if (DeadlineStatus::from($deadline->status) === DeadlineStatus::MISSED) {
            throw new IllegalDeadlineChange('Missed deadline is final; cannot be marked met');
        }

        $deadline->status = DeadlineStatus::MET->value;
        $deadline->save();

        return $deadline;
    }

    public function addDeadline(
        Application $application,
        DeadlineType $type,
        CarbonInterface $dueDate,
        User $actor,
        ?string $ipAddress = null,
    ): Deadline {
        $deadline = Deadline::query()->create([
            'application_id' => $application->id,
            'type' => $type->value,
            'due_date' => $dueDate,
            'status' => DeadlineStatus::OPEN->value,
        ]);

        $this->audit->record(
            'add_deadline:'.$type->value,
            actor: $actor,
            application: $application,
            ipAddress: $ipAddress,
        );

        return $deadline;
    }

    /**
     * @return Collection<int, Deadline>
     */
    public function listDeadlines(?string $applicationId = null): Collection
    {
        return Deadline::query()
            ->when($applicationId !== null, fn ($query) => $query->where('application_id', $applicationId))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
```

`backend/tests/Feature/DocketingServiceTest.php` — replace both `(new DocketingService)` with `app(DocketingService::class)`:

```php
    app(DocketingService::class)->markMet($deadline);
```

```php
    $result = app(DocketingService::class)->markMet($deadline);
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/DocketingDomainTest.php tests/Feature/DocketingServiceTest.php tests/Unit/DocketingSoleAuthorityTest.php`
Expected: PASS (4 + 2 + 1 tests).

- [x] **Step 5: Commit**

```bash
git add backend/app/Domain/Docketing backend/tests/Feature/DocketingDomainTest.php backend/tests/Feature/DocketingServiceTest.php
git commit -m "feat(l3): add deadline types, creation and listing to the docketing domain"
```

---

### Task 8: Deadline endpoints (`POST /applications/{id}/deadlines`, `GET /deadlines`)

**Files:**
- Create: `backend/app/Http/Controllers/DocketingController.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/DeadlineEndpointTest.php`

**Interfaces:**
- Consumes: `DocketingService`, `ApplicationIntakeService::findOrFail()`, `DeadlineType`.
- Produces: `DocketingController::store(Request, string $applicationId): JsonResponse` (`POST /applications/{applicationId}/deadlines`), `->index(Request): JsonResponse` (`GET /deadlines`, optional `application_id` filter); both staff-only.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Carbon;

it('adds a deadline for an application as staff', function (string $state) {
    $staff = User::factory()->{$state}()->create();
    $application = Application::query()->create(['title' => 'Docketed']);

    $response = $this->actingAs($staff)->postJson(
        "/applications/{$application->id}/deadlines?type=RESPONSE&due_date=2027-01-01T00:00:00Z",
    );

    $response->assertOk()
        ->assertJsonPath('application_id', $application->id)
        ->assertJsonPath('type', 'RESPONSE')
        ->assertJsonPath('status', 'OPEN');

    $this->assertDatabaseHas('deadlines', [
        'application_id' => $application->id,
        'type' => 'RESPONSE',
        'status' => 'OPEN',
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'add_deadline:RESPONSE', 'user_id' => $staff->id]);
})->with(['director', 'md']);

it('validates the deadline type and due date', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Validated']);

    $this->actingAs($director)
        ->postJson("/applications/{$application->id}/deadlines?due_date=2027-01-01T00:00:00Z")
        ->assertStatus(422)->assertJsonValidationErrors('type');

    $this->actingAs($director)
        ->postJson("/applications/{$application->id}/deadlines?type=NOPE&due_date=2027-01-01T00:00:00Z")
        ->assertStatus(422)->assertJsonValidationErrors('type');

    $this->actingAs($director)
        ->postJson("/applications/{$application->id}/deadlines?type=FILING&due_date=notadate")
        ->assertStatus(422)->assertJsonValidationErrors('due_date');
});

it('returns 404 for a deadline on an unknown application', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)
        ->postJson('/applications/unknown-app/deadlines?type=FILING&due_date=2027-01-01T00:00:00Z')
        ->assertNotFound()
        ->assertJsonPath('message', 'Application unknown-app not found');
});

it('lists deadlines for staff, filtered by application', function () {
    $md = User::factory()->md()->create();
    $first = Application::query()->create(['title' => 'First']);
    $second = Application::query()->create(['title' => 'Second']);
    $deadlines = app(\App\Domain\Docketing\DocketingService::class);
    $due = Carbon::parse('2026-01-01T00:00:00Z');

    $deadlines->addDeadline($first, \App\Domain\Docketing\DeadlineType::FILING, $due, $md);
    $deadlines->addDeadline($second, \App\Domain\Docketing\DeadlineType::RESPONSE, $due, $md);

    expect($this->actingAs($md)->getJson('/deadlines')->assertOk()->json())->toHaveCount(2)
        ->and($this->actingAs($md)->getJson("/deadlines?application_id={$first->id}")->assertOk()->json())->toHaveCount(1)
        ->and($this->actingAs($md)->getJson('/deadlines?application_id=unknown-app')->assertOk()->json())->toBe([]);
});

it('gates the deadline endpoints to staff', function () {
    $application = Application::query()->create(['title' => 'Gated']);
    $uri = "/applications/{$application->id}/deadlines?type=FILING&due_date=2027-01-01T00:00:00Z";

    $this->postJson($uri)->assertUnauthorized();
    $this->getJson('/deadlines')->assertUnauthorized();

    $user = User::factory()->create();
    $this->actingAs($user)->postJson($uri)->assertForbidden();
    $this->actingAs($user)->getJson('/deadlines')->assertForbidden();
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/DeadlineEndpointTest.php`
Expected: FAIL with 404 on `POST /applications/{id}/deadlines` (route not defined).

- [x] **Step 3: Write minimal implementation**

`backend/app/Http/Controllers/DocketingController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Docketing\DeadlineType;
use App\Domain\Docketing\DocketingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Thin controller for the docket. Deadline rules live in App\Domain\Docketing.
 */
final class DocketingController extends Controller
{
    public function __construct(
        private readonly DocketingService $docketing,
        private readonly ApplicationIntakeService $intake,
    ) {}

    public function store(Request $request, string $applicationId): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(DeadlineType::class)],
            'due_date' => ['required', 'date'],
        ]);

        $deadline = $this->docketing->addDeadline(
            $this->intake->findOrFail($applicationId),
            DeadlineType::from($validated['type']),
            Carbon::parse($validated['due_date']),
            $request->user(),
            $request->ip(),
        );

        return response()->json($deadline);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'application_id' => ['sometimes', 'string'],
        ]);

        return response()->json($this->docketing->listDeadlines($validated['application_id'] ?? null));
    }
}
```

`backend/routes/web.php` — add:

```php
Route::post('/applications/{applicationId}/deadlines', [DocketingController::class, 'store'])
    ->middleware(['auth', 'role:director,md'])
    ->name('deadlines.store');

Route::get('/deadlines', [DocketingController::class, 'index'])
    ->middleware(['auth', 'role:director,md'])
    ->name('deadlines.index');
```

and the `use App\Http\Controllers\DocketingController;` import.

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/DeadlineEndpointTest.php`
Expected: PASS (5 tests).

- [x] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/DocketingController.php backend/routes/web.php backend/tests/Feature/DeadlineEndpointTest.php
git commit -m "feat(l3): add staff-only deadline endpoints"
```

---

### Task 9: Prosecution domain — office actions auto-docket a response deadline

**Files:**
- Create: `backend/app/Domain/Prosecution/OfficeActionKind.php`
- Create: `backend/app/Domain/Prosecution/UnknownOfficeAction.php`
- Create: `backend/app/Domain/Prosecution/ProsecutionService.php`
- Test: `backend/tests/Feature/ProsecutionDomainTest.php`

**Interfaces:**
- Consumes: `DocketingService::addDeadline()`, `AuditRecorder::record()`, `App\Models\{Application,OfficeAction,Response,User}`.
- Produces: `OfficeActionKind` enum (`REJECTION`/`OBJECTION`/`ALLOWANCE`); `UnknownOfficeAction` (`RuntimeException`, message `unknown office_action_id: {id}`); `ProsecutionService::receiveOfficeAction(Application $application, OfficeActionKind $kind, string $body, User $actor, ?string $ipAddress = null): OfficeAction`, `->fileResponse(Application $application, OfficeAction $officeAction, string $body): Response`, and public const `RESPONSE_WINDOW_DAYS = 90`.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Domain\Prosecution\OfficeActionKind;
use App\Domain\Prosecution\ProsecutionService;
use App\Domain\Prosecution\UnknownOfficeAction;
use App\Models\Application;
use App\Models\Deadline;
use App\Models\OfficeAction;
use App\Models\Response;
use App\Models\User;
use Illuminate\Support\Carbon;

it('dockets one response deadline 90 days out for a rejection', function () {
    $this->travelTo(Carbon::parse('2026-01-01T12:00:00Z'));

    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Prosecuted']);

    $action = app(ProsecutionService::class)->receiveOfficeAction(
        $application,
        OfficeActionKind::REJECTION,
        'Prior art cited',
        $director,
    );

    expect($action->kind)->toBe('REJECTION')
        ->and($action->body)->toBe('Prior art cited')
        ->and($action->application_id)->toBe($application->id)
        ->and($action->received_at)->not->toBeNull();

    $deadline = Deadline::query()->sole();

    expect($deadline->type)->toBe('RESPONSE')
        ->and($deadline->status)->toBe('OPEN')
        ->and($deadline->application_id)->toBe($application->id)
        ->and($deadline->due_date->toIso8601String())->toBe(Carbon::parse('2026-04-01T12:00:00Z')->toIso8601String());
});

it('does not docket a deadline for an allowance', function () {
    $application = Application::query()->create(['title' => 'Allowed']);

    app(ProsecutionService::class)->receiveOfficeAction(
        $application,
        OfficeActionKind::ALLOWANCE,
        'Allowed as filed',
        User::factory()->director()->create(),
    );

    $this->assertDatabaseCount('deadlines', 0);
    $this->assertDatabaseCount('office_actions', 1);
});

it('dockets a response deadline for an objection and audits both changes', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Objected']);

    app(ProsecutionService::class)->receiveOfficeAction($application, OfficeActionKind::OBJECTION, 'Clarity', $director);

    $this->assertDatabaseHas('audit_logs', ['action' => 'receive_office_action:OBJECTION', 'user_id' => $director->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'add_deadline:RESPONSE', 'user_id' => $director->id]);
});

it('files a response against an office action of the same application', function () {
    $application = Application::query()->create(['title' => 'Answered']);
    $service = app(ProsecutionService::class);
    $action = $service->receiveOfficeAction(
        $application,
        OfficeActionKind::REJECTION,
        'Fix claim 1',
        User::factory()->director()->create(),
    );

    $response = $service->fileResponse($application, $action, 'Amended claim 1');

    expect($response->office_action_id)->toBe($action->id)
        ->and($response->application_id)->toBe($application->id)
        ->and($response->body)->toBe('Amended claim 1')
        ->and(Response::query()->count())->toBe(1);
});

it('refuses a response filed against another application office action', function () {
    $service = app(ProsecutionService::class);
    $actor = User::factory()->director()->create();
    $owner = Application::query()->create(['title' => 'Owner']);
    $other = Application::query()->create(['title' => 'Other']);
    $action = $service->receiveOfficeAction($owner, OfficeActionKind::REJECTION, 'Fix claim 1', $actor);

    $service->fileResponse($other, $action, 'Wrong application');
})->throws(UnknownOfficeAction::class);
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/ProsecutionDomainTest.php`
Expected: FAIL — `Class "App\Domain\Prosecution\OfficeActionKind" not found`.

- [x] **Step 3: Write minimal implementation**

`backend/app/Domain/Prosecution/OfficeActionKind.php`:

```php
<?php

namespace App\Domain\Prosecution;

/**
 * Office-action categories (CONTEXT.md §Domain Glossary). Values match the
 * `office_actions.kind` column defined in the L0 schema.
 */
enum OfficeActionKind: string
{
    case REJECTION = 'REJECTION';
    case OBJECTION = 'OBJECTION';
    case ALLOWANCE = 'ALLOWANCE';
}
```

`backend/app/Domain/Prosecution/UnknownOfficeAction.php`:

```php
<?php

namespace App\Domain\Prosecution;

use RuntimeException;

/**
 * Raised when a response is filed against an office action belonging to a
 * different application.
 */
final class UnknownOfficeAction extends RuntimeException
{
    public function __construct(public readonly string $officeActionId)
    {
        parent::__construct("unknown office_action_id: {$officeActionId}");
    }
}
```

`backend/app/Domain/Prosecution/ProsecutionService.php`:

```php
<?php

namespace App\Domain\Prosecution;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Docketing\DeadlineType;
use App\Domain\Docketing\DocketingService;
use App\Models\Application;
use App\Models\OfficeAction;
use App\Models\Response;
use App\Models\User;
use Illuminate\Support\Carbon as SupportCarbon;

/**
 * Prosecution — port of pre-laravel-final-state
 * `app/modules/prosecution/local/__init__.py`. The docketing module stays
 * the sole authority on deadlines (invariant 4), so this service asks it
 * to create the response deadline instead of writing one itself.
 */
final class ProsecutionService
{
    /** Calendar days a director has to answer an office action. */
    public const int RESPONSE_WINDOW_DAYS = 90;

    public function __construct(
        private readonly DocketingService $docketing,
        private readonly AuditRecorder $audit,
    ) {}

    public function receiveOfficeAction(
        Application $application,
        OfficeActionKind $kind,
        string $body,
        User $actor,
        ?string $ipAddress = null,
    ): OfficeAction {
        $officeAction = OfficeAction::query()->create([
            'application_id' => $application->id,
            'kind' => $kind->value,
            'body' => $body,
        ]);

        // An allowance needs no response; everything else auto-dockets one.
        if ($kind !== OfficeActionKind::ALLOWANCE) {
            $this->docketing->addDeadline(
                $application,
                DeadlineType::RESPONSE,
                SupportCarbon::now()->addDays(self::RESPONSE_WINDOW_DAYS),
                $actor,
                $ipAddress,
            );
        }

        $this->audit->record(
            'receive_office_action:'.$kind->value,
            actor: $actor,
            application: $application,
            ipAddress: $ipAddress,
        );

        return $officeAction;
    }

    /**
     * @throws UnknownOfficeAction when the office action belongs to another application
     */
    public function fileResponse(Application $application, OfficeAction $officeAction, string $body): Response
    {
        if ($officeAction->application_id !== $application->id) {
            throw new UnknownOfficeAction($officeAction->id);
        }

        return Response::query()->create([
            'application_id' => $application->id,
            'office_action_id' => $officeAction->id,
            'body' => $body,
        ]);
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/ProsecutionDomainTest.php`
Expected: PASS (5 tests).

- [x] **Step 5: Commit**

```bash
git add backend/app/Domain/Prosecution backend/tests/Feature/ProsecutionDomainTest.php
git commit -m "feat(l3): add prosecution domain with auto-docketed response deadlines"
```

---

### Task 10: Office-action endpoint (`POST /applications/{id}/office-actions`)

**Files:**
- Create: `backend/app/Http/Controllers/OfficeActionController.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/OfficeActionEndpointTest.php`

**Interfaces:**
- Consumes: `ProsecutionService`, `ApplicationIntakeService::findOrFail()`, `OfficeActionKind`.
- Produces: `OfficeActionController::store(Request, string $applicationId): JsonResponse` (`POST /applications/{applicationId}/office-actions`, staff-only), 200 with the office-action JSON.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Models\Application;
use App\Models\User;

it('receives an office action and dockets the response deadline', function (string $state) {
    $staff = User::factory()->{$state}()->create();
    $application = Application::query()->create(['title' => 'Prosecuted']);

    $response = $this->actingAs($staff)->postJson(
        "/applications/{$application->id}/office-actions?kind=REJECTION&body=Prior%20art%20cited",
    );

    $response->assertOk()
        ->assertJsonPath('kind', 'REJECTION')
        ->assertJsonPath('body', 'Prior art cited')
        ->assertJsonPath('application_id', $application->id);

    $this->assertDatabaseHas('office_actions', ['application_id' => $application->id, 'kind' => 'REJECTION']);
    $this->assertDatabaseHas('deadlines', ['application_id' => $application->id, 'type' => 'RESPONSE', 'status' => 'OPEN']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'receive_office_action:REJECTION', 'user_id' => $staff->id]);
})->with(['director', 'md']);

it('does not docket a deadline for an allowance', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Allowed']);

    $this->actingAs($director)->postJson(
        "/applications/{$application->id}/office-actions?kind=ALLOWANCE&body=Allowed%20as%20filed",
    )->assertOk();

    $this->assertDatabaseCount('deadlines', 0);
});

it('validates the kind and body', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Validated']);

    $this->actingAs($director)
        ->postJson("/applications/{$application->id}/office-actions?body=no%20kind")
        ->assertStatus(422)->assertJsonValidationErrors('kind');

    $this->actingAs($director)
        ->postJson("/applications/{$application->id}/office-actions?kind=SHRUG&body=x")
        ->assertStatus(422)->assertJsonValidationErrors('kind');

    $this->actingAs($director)
        ->postJson("/applications/{$application->id}/office-actions?kind=REJECTION")
        ->assertStatus(422)->assertJsonValidationErrors('body');
});

it('returns 404 for an office action on an unknown application', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)
        ->postJson('/applications/unknown-app/office-actions?kind=REJECTION&body=x')
        ->assertNotFound()
        ->assertJsonPath('message', 'Application unknown-app not found');
});

it('gates office actions to staff', function () {
    $application = Application::query()->create(['title' => 'Gated']);
    $uri = "/applications/{$application->id}/office-actions?kind=REJECTION&body=x";

    $this->postJson($uri)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($uri)->assertForbidden();
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/OfficeActionEndpointTest.php`
Expected: FAIL with 404 on `POST /applications/{id}/office-actions` (route not defined).

- [x] **Step 3: Write minimal implementation**

`backend/app/Http/Controllers/OfficeActionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Prosecution\OfficeActionKind;
use App\Domain\Prosecution\ProsecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Thin controller for prosecution. Auto-docketing rules live in
 * App\Domain\Prosecution.
 */
final class OfficeActionController extends Controller
{
    public function __construct(
        private readonly ProsecutionService $prosecution,
        private readonly ApplicationIntakeService $intake,
    ) {}

    public function store(Request $request, string $applicationId): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::enum(OfficeActionKind::class)],
            'body' => ['required', 'string'],
        ]);

        return response()->json($this->prosecution->receiveOfficeAction(
            $this->intake->findOrFail($applicationId),
            OfficeActionKind::from($validated['kind']),
            (string) $validated['body'],
            $request->user(),
            $request->ip(),
        ));
    }
}
```

`backend/routes/web.php` — add:

```php
Route::post('/applications/{applicationId}/office-actions', [OfficeActionController::class, 'store'])
    ->middleware(['auth', 'role:director,md'])
    ->name('office-actions.store');
```

and the `use App\Http\Controllers\OfficeActionController;` import.

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/OfficeActionEndpointTest.php`
Expected: PASS (5 tests).

- [x] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/OfficeActionController.php backend/routes/web.php backend/tests/Feature/OfficeActionEndpointTest.php
git commit -m "feat(l3): add staff-only office-action endpoint"
```

---

### Task 11: Filing workflow domain — file, acknowledge, filing record

**Files:**
- Create: `backend/app/Domain/Filing/AcknowledgementWithoutFiling.php`
- Create: `backend/app/Domain/Filing/FilingWorkflowService.php`
- Test: `backend/tests/Feature/FilingWorkflowTest.php`

**Interfaces:**
- Consumes: `App\Domain\Lifecycle\{ApplicationStatus,LifecycleService}`, `App\Domain\Notification\NotificationService::notifyInventors()`, `App\Domain\Audit\AuditRecorder::record()`, `App\Models\{Application,FilingRecord,User}`.
- Produces: `AcknowledgementWithoutFiling` (`RuntimeException`, message `No filing record found for {id} - mark filed first`); `FilingWorkflowService::__construct(LifecycleService, NotificationService, AuditRecorder)`, `->filingRecord(Application $application): ?FilingRecord`, `->markFiled(Application $application, User $actor): Application`, `->acknowledgeNipo(Application $application, User $actor): Application`, and the private `->reference(Application $application): string` used in notification bodies.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Domain\Filing\AcknowledgementWithoutFiling;
use App\Domain\Filing\FilingWorkflowService;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\IllegalTransition;
use App\Domain\Lifecycle\LifecycleService;
use App\Models\Application;
use App\Models\FilingRecord;
use App\Models\Inventor;
use App\Models\Notification;
use App\Models\User;

function filingReadyApplication(array $emails = ['ada@pdn.ac.lk']): Application
{
    $application = Application::query()->create(['title' => 'Journey Widget', 'inventor_email' => $emails[0]]);

    foreach ($emails as $email) {
        Inventor::query()->create([
            'application_id' => $application->id,
            'inventor_name' => 'Inventor',
            'inventor_email' => $email,
        ]);
    }

    return $application;
}

it('marks an application filed, records the filing and notifies every inventor', function () {
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    $application = filingReadyApplication(['ada@pdn.ac.lk', 'bob@pdn.ac.lk']);

    app(FilingWorkflowService::class)->markFiled($application, $director);

    $record = FilingRecord::query()->sole();
    $reference = substr($application->id, 0, 8);

    expect($application->refresh()->status)->toBe('FILED')
        ->and($record->application_id)->toBe($application->id)
        ->and($record->filed_by)->toBe('director@pdn.ac.lk')
        ->and($record->filed_date)->not->toBeNull()
        ->and(Notification::query()->count())->toBe(2)
        ->and(Notification::query()->first()->subject)->toBe('Patent Filed')
        ->and(Notification::query()->first()->body)
        ->toBe("Your patent application Journey Widget ({$reference}) has been filed with NIPO.");

    $this->assertDatabaseHas('audit_logs', ['action' => 'filing:mark_filed', 'user_id' => $director->id]);
    $this->assertDatabaseHas('status_history', ['application_id' => $application->id, 'new_status' => 'FILED']);
});

it('keeps a single filing record when filed twice', function () {
    $director = User::factory()->director()->create();
    $application = filingReadyApplication();

    $service = app(FilingWorkflowService::class);
    $service->markFiled($application, $director);
    $service->markFiled($application, $director);

    expect(FilingRecord::query()->count())->toBe(1);
});

it('refuses to file an application whose status cannot reach FILED', function () {
    $director = User::factory()->director()->create();
    $application = filingReadyApplication();
    $application->status = ApplicationStatus::GRANTED->value;
    $application->save();

    app(FilingWorkflowService::class)->markFiled($application, $director);
})->throws(IllegalTransition::class);

it('records the NIPO acknowledgement on the filing record', function () {
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = filingReadyApplication();
    $service = app(FilingWorkflowService::class);
    $service->markFiled($application, $md);
    Notification::query()->delete();

    $service->acknowledgeNipo($application, $md);

    $record = FilingRecord::query()->sole();
    $reference = substr($application->id, 0, 8);

    expect($application->refresh()->status)->toBe('ACKNOWLEDGED')
        ->and($record->nipo_acknowledged_by)->toBe('md@pdn.ac.lk')
        ->and($record->nipo_acknowledged_at)->not->toBeNull()
        ->and(Notification::query()->sole()->subject)->toBe('Acknowledged')
        ->and(Notification::query()->sole()->body)
        ->toBe("NIPO has acknowledged receipt of your patent application Journey Widget ({$reference}).");

    $this->assertDatabaseHas('audit_logs', ['action' => 'filing:acknowledge_nipo']);
});

it('refuses to acknowledge an application that was never filed', function () {
    $application = filingReadyApplication();

    app(FilingWorkflowService::class)->acknowledgeNipo($application, User::factory()->md()->create());
})->throws(AcknowledgementWithoutFiling::class);

it('returns the filing record or null', function () {
    $application = filingReadyApplication();
    $service = app(FilingWorkflowService::class);

    expect($service->filingRecord($application))->toBeNull();

    $service->markFiled($application, User::factory()->director()->create());

    expect($service->filingRecord($application))->not->toBeNull();
});

it('leaves the lifecycle service pure', function () {
    $application = filingReadyApplication();

    (new LifecycleService)->changeStatus($application, ApplicationStatus::FILED, changedBy: 'md@pdn.ac.lk', reason: 'Manual');

    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/FilingWorkflowTest.php`
Expected: FAIL — `Class "App\Domain\Filing\FilingWorkflowService" not found`.

- [x] **Step 3: Write minimal implementation**

`backend/app/Domain/Filing/AcknowledgementWithoutFiling.php`:

```php
<?php

namespace App\Domain\Filing;

use RuntimeException;

/**
 * Raised when NIPO acknowledgement is recorded before the application was
 * filed. Rendered as HTTP 404 by bootstrap/app.php (reference parity).
 */
final class AcknowledgementWithoutFiling extends RuntimeException
{
    public function __construct(public readonly string $applicationId)
    {
        parent::__construct("No filing record found for {$applicationId} - mark filed first");
    }
}
```

`backend/app/Domain/Filing/FilingWorkflowService.php`:

```php
<?php

namespace App\Domain\Filing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\LifecycleService;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\FilingRecord;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Filing workflow — port of pre-laravel-final-state
 * `app/modules/filing_workflow/local/__init__.py`. Every step changes the
 * status through the lifecycle domain, notifies every inventor and writes
 * its own audit row (invariants 2 and 5).
 */
final class FilingWorkflowService
{
    public function __construct(
        private readonly LifecycleService $lifecycle,
        private readonly NotificationService $notifications,
        private readonly AuditRecorder $audit,
    ) {}

    public function filingRecord(Application $application): ?FilingRecord
    {
        return FilingRecord::query()->where('application_id', $application->id)->first();
    }

    public function markFiled(Application $application, User $actor): Application
    {
        $this->lifecycle->changeStatus(
            $application,
            ApplicationStatus::FILED,
            changedBy: (string) $actor->email,
            reason: 'Application filed with NIPO',
        );

        FilingRecord::query()->updateOrCreate(
            ['application_id' => $application->id],
            ['filed_date' => Carbon::now(), 'filed_by' => (string) $actor->email],
        );

        $this->notifications->notifyInventors(
            $application,
            'Patent Filed',
            "Your patent application {$application->title} ({$this->reference($application)}) has been filed with NIPO.",
        );

        $this->audit->record('filing:mark_filed', actor: $actor, application: $application);

        return $application;
    }

    /**
     * @throws AcknowledgementWithoutFiling when the application was never filed
     */
    public function acknowledgeNipo(Application $application, User $actor): Application
    {
        $record = $this->filingRecord($application)
            ?? throw new AcknowledgementWithoutFiling($application->id);

        $this->lifecycle->changeStatus(
            $application,
            ApplicationStatus::ACKNOWLEDGED,
            changedBy: (string) $actor->email,
            reason: 'NIPO acknowledgement recorded',
        );

        $record->nipo_acknowledged_at = Carbon::now();
        $record->nipo_acknowledged_by = (string) $actor->email;
        $record->save();

        $this->notifications->notifyInventors(
            $application,
            'Acknowledged',
            "NIPO has acknowledged receipt of your patent application {$application->title} ({$this->reference($application)}).",
        );

        $this->audit->record('filing:acknowledge_nipo', actor: $actor, application: $application);

        return $application;
    }

    private function reference(Application $application): string
    {
        return substr($application->id, 0, 8);
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/FilingWorkflowTest.php`
Expected: PASS (7 tests).

- [x] **Step 5: Commit**

```bash
git add backend/app/Domain/Filing backend/tests/Feature/FilingWorkflowTest.php
git commit -m "feat(l3): add filing workflow filing and acknowledgement steps"
```

---

### Task 12: Filing workflow — defect sheets (1–3, unique number, lifecycle-coupled status)

**Files:**
- Create: `backend/app/Domain/Filing/DefectSheetRejected.php`
- Modify: `backend/app/Domain/Filing/FilingWorkflowService.php`
- Test: `backend/tests/Feature/DefectSheetTest.php`

**Interfaces:**
- Consumes: Everything from Task 11 plus `App\Models\DefectSheet`.
- Produces: `DefectSheetRejected` (`RuntimeException`, message is the exact reference string); `FilingWorkflowService::recordDefectSheet(Application $application, int $sheetNumber, string $description, User $actor): DefectSheet` and `->defectSheets(Application $application): Collection<int, DefectSheet>`.

> The reference's third guard (`Maximum 3 defect sheets allowed for {id}`) is kept verbatim for parity, but is unreachable once the 1–3 and duplicate-number guards pass; only the number and duplicate guards get tests.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Domain\Filing\DefectSheetRejected;
use App\Domain\Filing\FilingWorkflowService;
use App\Domain\Lifecycle\IllegalTransition;
use App\Models\Application;
use App\Models\DefectSheet;
use App\Models\FilingRecord;
use App\Models\Inventor;
use App\Models\Notification;
use App\Models\User;

function filedApplication(): Application
{
    $application = Application::query()->create(['title' => 'Defect Flow', 'inventor_email' => 'ada@pdn.ac.lk']);
    Inventor::query()->create([
        'application_id' => $application->id,
        'inventor_name' => 'Ada',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);
    app(FilingWorkflowService::class)->markFiled($application, User::factory()->director()->create());

    return $application->refresh();
}

it('records a defect sheet, moves the status and updates the filing record', function () {
    $director = User::factory()->director()->create();
    $application = filedApplication();
    Notification::query()->delete();

    $sheet = app(FilingWorkflowService::class)->recordDefectSheet($application, 1, 'Missing signature', $director);

    $reference = substr($application->id, 0, 8);

    expect($application->refresh()->status)->toBe('DEFECT_SHEET_1')
        ->and($sheet->sheet_number)->toBe(1)
        ->and($sheet->description)->toBe('Missing signature')
        ->and($sheet->application_id)->toBe($application->id)
        ->and(FilingRecord::query()->sole()->defect_sheet_count)->toBe(1)
        ->and(Notification::query()->sole()->subject)->toBe('Defect Sheet Received')
        ->and(Notification::query()->sole()->body)
        ->toBe("Defect sheet #1 has been received for your patent application Defect Flow ({$reference}): Missing signature");

    $this->assertDatabaseHas('audit_logs', ['action' => 'filing:defect_sheet', 'user_id' => $director->id]);
    $this->assertDatabaseHas('status_history', ['application_id' => $application->id, 'new_status' => 'DEFECT_SHEET_1']);
});

it('allows a later sheet number to be applied directly from ACKNOWLEDGED', function () {
    $md = User::factory()->md()->create();
    $application = filedApplication();
    app(FilingWorkflowService::class)->acknowledgeNipo($application, $md);

    $sheet = app(FilingWorkflowService::class)->recordDefectSheet($application->refresh(), 2, 'Clarity', $md);

    expect($sheet->sheet_number)->toBe(2)
        ->and($application->refresh()->status)->toBe('DEFECT_SHEET_2');
});

it('rejects a sheet number outside 1 to 3', function (int $sheetNumber) {
    $application = filedApplication();

    app(FilingWorkflowService::class)->recordDefectSheet($application, $sheetNumber, 'x', User::factory()->director()->create());
})->throws(DefectSheetRejected::class)->with([0, 4]);

it('rejects a repeated sheet number', function () {
    $director = User::factory()->director()->create();
    $application = filedApplication();
    $service = app(FilingWorkflowService::class);
    $service->recordDefectSheet($application, 1, 'First', $director);
    Notification::query()->delete();

    try {
        $service->recordDefectSheet($application->refresh(), 1, 'Again', $director);
        $this->fail('Expected the duplicate sheet to be rejected.');
    } catch (DefectSheetRejected $exception) {
        expect($exception->getMessage())->toBe("Defect sheet #1 already exists for {$application->id}");
    }

    expect(DefectSheet::query()->count())->toBe(1);
    $this->assertDatabaseCount('notifications', 0);
});

it('lets the transition table reject a sheet number the lifecycle forbids', function () {
    $application = filedApplication();
    app(FilingWorkflowService::class)->recordDefectSheet($application, 1, 'First', User::factory()->director()->create());

    // DEFECT_SHEET_1 may only reach DEFECT_SHEET_2, never DEFECT_SHEET_3.
    app(FilingWorkflowService::class)->recordDefectSheet($application->refresh(), 3, 'Skip', User::factory()->director()->create());
})->throws(IllegalTransition::class);

it('lists the defect sheets of an application in sheet order', function () {
    $director = User::factory()->director()->create();
    $application = filedApplication();
    $service = app(FilingWorkflowService::class);
    $service->recordDefectSheet($application, 1, 'First', $director);
    $service->recordDefectSheet($application->refresh(), 2, 'Second', $director);

    expect($service->defectSheets($application)->pluck('sheet_number')->all())->toBe([1, 2])
        ->and($service->defectSheets(Application::query()->create(['title' => 'Other'])))->toBeEmpty();
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/DefectSheetTest.php`
Expected: FAIL — `Class "App\Domain\Filing\DefectSheetRejected" not found`.
- [x] **Step 3: Write minimal implementation**

`backend/app/Domain/Filing/DefectSheetRejected.php`:

```php
<?php

namespace App\Domain\Filing;

use RuntimeException;

/**
 * Raised when a defect sheet may not be recorded (bad number, repeat, cap).
 * The message is the reference string; the API layer renders it as HTTP 422.
 */
final class DefectSheetRejected extends RuntimeException {}
```

`backend/app/Domain/Filing/FilingWorkflowService.php` — add these methods and the `DefectSheet` + `Collection` imports:

```php
    /**
     * @return Collection<int, DefectSheet>
     */
    public function defectSheets(Application $application): Collection
    {
        return DefectSheet::query()
            ->where('application_id', $application->id)
            ->orderBy('sheet_number')
            ->get();
    }

    /**
     * @throws DefectSheetRejected
     * @throws IllegalTransition when the current status may not reach DEFECT_SHEET_{n}
     */
    public function recordDefectSheet(Application $application, int $sheetNumber, string $description, User $actor): DefectSheet
    {
        if (! in_array($sheetNumber, [1, 2, 3], true)) {
            throw new DefectSheetRejected("Invalid defect sheet number: {$sheetNumber} (must be 1-3)");
        }

        $existing = $this->defectSheets($application);

        if ($existing->contains(fn (DefectSheet $sheet): bool => $sheet->sheet_number === $sheetNumber)) {
            throw new DefectSheetRejected("Defect sheet #{$sheetNumber} already exists for {$application->id}");
        }

        if ($existing->count() >= 3) {
            throw new DefectSheetRejected("Maximum 3 defect sheets allowed for {$application->id}");
        }

        $this->lifecycle->changeStatus(
            $application,
            ApplicationStatus::from("DEFECT_SHEET_{$sheetNumber}"),
            changedBy: (string) $actor->email,
            reason: "Defect sheet #{$sheetNumber} recorded",
        );

        $sheet = DefectSheet::query()->create([
            'application_id' => $application->id,
            'sheet_number' => $sheetNumber,
            'description' => $description,
        ]);

        $record = $this->filingRecord($application);

        if ($record !== null) {
            $record->defect_sheet_count = $existing->count() + 1;
            $record->save();
        }

        $this->notifications->notifyInventors(
            $application,
            'Defect Sheet Received',
            "Defect sheet #{$sheetNumber} has been received for your patent application {$application->title} ({$this->reference($application)}): {$description}",
        );

        $this->audit->record('filing:defect_sheet', actor: $actor, application: $application);

        return $sheet;
    }
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/DefectSheetTest.php`
Expected: PASS (6 tests).

- [x] **Step 5: Commit**

```bash
git add backend/app/Domain/Filing backend/tests/Feature/DefectSheetTest.php
git commit -m "feat(l3): record defect sheets with number, duplicate and lifecycle guards"
```

---

### Task 13: Filing workflow — grant and reject

**Files:**
- Modify: `backend/app/Domain/Filing/FilingWorkflowService.php`
- Test: `backend/tests/Feature/FilingOutcomeTest.php`

**Interfaces:**
- Produces: `FilingWorkflowService::markGranted(Application $application, string $patentNumber, User $actor): Application` and `->markRejected(Application $application, User $actor): Application`.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Domain\Filing\FilingWorkflowService;
use App\Domain\Lifecycle\IllegalTransition;
use App\Models\Application;
use App\Models\FilingRecord;
use App\Models\Inventor;
use App\Models\Notification;
use App\Models\User;

function acknowledgedApplication(): Application
{
    $application = Application::query()->create(['title' => 'Grant Flow', 'inventor_email' => 'ada@pdn.ac.lk']);
    Inventor::query()->create([
        'application_id' => $application->id,
        'inventor_name' => 'Ada',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $service = app(FilingWorkflowService::class);
    $service->markFiled($application, $md);
    $service->acknowledgeNipo($application->refresh(), $md);

    return $application->refresh();
}

it('grants a patent and stamps the filing record', function () {
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    $application = acknowledgedApplication();
    Notification::query()->delete();

    app(FilingWorkflowService::class)->markGranted($application, 'LK/PAT/2026/999', $director);

    $record = FilingRecord::query()->sole();

    expect($application->refresh()->status)->toBe('GRANTED')
        ->and($record->patent_number)->toBe('LK/PAT/2026/999')
        ->and($record->granted_by)->toBe('director@pdn.ac.lk')
        ->and($record->granted_at)->not->toBeNull()
        ->and(Notification::query()->sole()->subject)->toBe('Patent Received')
        ->and(Notification::query()->sole()->body)
        ->toBe('Your patent Grant Flow has been granted with patent number LK/PAT/2026/999.');

    $this->assertDatabaseHas('audit_logs', ['action' => 'filing:granted', 'user_id' => $director->id]);
});

it('grants without a filing record and still notifies', function () {
    $application = Application::query()->create(['title' => 'Direct Grant', 'inventor_email' => 'ada@pdn.ac.lk', 'status' => 'ACKNOWLEDGED']);
    Inventor::query()->create([
        'application_id' => $application->id,
        'inventor_name' => 'Ada',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);

    app(FilingWorkflowService::class)->markGranted($application, 'LK/PAT/2026/1', User::factory()->director()->create());

    expect($application->refresh()->status)->toBe('GRANTED')
        ->and(FilingRecord::query()->count())->toBe(0)
        ->and(Notification::query()->sole()->subject)->toBe('Patent Received');
});

it('refuses to grant straight from DRAFT and writes nothing', function () {
    $application = Application::query()->create(['title' => 'Jump', 'inventor_email' => 'ada@pdn.ac.lk']);

    app(FilingWorkflowService::class)->markGranted($application, 'LK/PAT/2026/2', User::factory()->director()->create());
})->throws(IllegalTransition::class);

it('rejects a filed application and stamps the filing record', function () {
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Reject Flow', 'inventor_email' => 'ada@pdn.ac.lk']);
    Inventor::query()->create([
        'application_id' => $application->id,
        'inventor_name' => 'Ada',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);
    $service = app(FilingWorkflowService::class);
    $service->markFiled($application, $director);
    Notification::query()->delete();

    $service->markRejected($application->refresh(), $director);

    $record = FilingRecord::query()->sole();
    $reference = substr($application->id, 0, 8);

    expect($application->refresh()->status)->toBe('REJECTED')
        ->and($record->rejected_by)->toBe('director@pdn.ac.lk')
        ->and($record->rejected_at)->not->toBeNull()
        ->and(Notification::query()->sole()->subject)->toBe('Rejected')
        ->and(Notification::query()->sole()->body)
        ->toBe("Your patent application Reject Flow ({$reference}) has been rejected.");

    $this->assertDatabaseHas('audit_logs', ['action' => 'filing:rejected', 'user_id' => $director->id]);
});

it('refuses to reject straight from DRAFT', function () {
    $application = Application::query()->create(['title' => 'Jump', 'inventor_email' => 'ada@pdn.ac.lk']);

    app(FilingWorkflowService::class)->markRejected($application, User::factory()->director()->create());
})->throws(IllegalTransition::class);
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/FilingOutcomeTest.php`
Expected: FAIL — `Call to undefined method App\Domain\Filing\FilingWorkflowService::markGranted()`.

- [x] **Step 3: Write minimal implementation**

Add to `backend/app/Domain/Filing/FilingWorkflowService.php`:

```php
    public function markGranted(Application $application, string $patentNumber, User $actor): Application
    {
        $this->lifecycle->changeStatus(
            $application,
            ApplicationStatus::GRANTED,
            changedBy: (string) $actor->email,
            reason: "Patent granted ({$patentNumber})",
        );

        $record = $this->filingRecord($application);

        if ($record !== null) {
            $record->granted_at = Carbon::now();
            $record->granted_by = (string) $actor->email;
            $record->patent_number = $patentNumber;
            $record->save();
        }

        $this->notifications->notifyInventors(
            $application,
            'Patent Received',
            "Your patent {$application->title} has been granted with patent number {$patentNumber}.",
        );

        $this->audit->record('filing:granted', actor: $actor, application: $application);

        return $application;
    }

    public function markRejected(Application $application, User $actor): Application
    {
        $this->lifecycle->changeStatus(
            $application,
            ApplicationStatus::REJECTED,
            changedBy: (string) $actor->email,
            reason: 'Application rejected',
        );

        $record = $this->filingRecord($application);

        if ($record !== null) {
            $record->rejected_at = Carbon::now();
            $record->rejected_by = (string) $actor->email;
            $record->save();
        }

        $this->notifications->notifyInventors(
            $application,
            'Rejected',
            "Your patent application {$application->title} ({$this->reference($application)}) has been rejected.",
        );

        $this->audit->record('filing:rejected', actor: $actor, application: $application);

        return $application;
    }
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/FilingOutcomeTest.php`
Expected: PASS (5 tests).

- [x] **Step 5: Commit**

```bash
git add backend/app/Domain/Filing/FilingWorkflowService.php backend/tests/Feature/FilingOutcomeTest.php
git commit -m "feat(l3): grant and reject filing outcomes"
```

---

### Task 14: Filing endpoints (`/admin/filing/{id}/...`)

**Files:**
- Create: `backend/app/Http/Controllers/FilingController.php`
- Modify: `backend/routes/web.php`
- Modify: `backend/bootstrap/app.php` (render `DefectSheetRejected` → 422, `AcknowledgementWithoutFiling` → 404)
- Test: `backend/tests/Feature/FilingEndpointTest.php`

**Interfaces:**
- Consumes: `FilingWorkflowService`, `ApplicationIntakeService::findOrFail()`, the existing renders for `IllegalTransition` (422) and `ApplicationNotFound` (404).
- Produces: `FilingController::file`, `->acknowledge`, `->storeDefectSheet`, `->defectSheets`, `->grant`, `->reject`, `->status` on the staff-only group `admin/filing/{applicationId}` → routes `filing.file`, `filing.acknowledge`, `filing.defect-sheets.store`, `filing.defect-sheets.index`, `filing.grant`, `filing.reject`, `filing.status`.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Models\Application;
use App\Models\Deadline;
use App\Models\FilingRecord;
use App\Models\Inventor;
use App\Models\User;

function filingApplication(): Application
{
    return Application::query()->create(['title' => 'Flow Test Invention', 'inventor_email' => 'ada@pdn.ac.lk']);
}

it('drives the filing chain from file to grant', function () {
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = filingApplication();
    Inventor::query()->create([
        'application_id' => $application->id,
        'inventor_name' => 'Ada',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/file")->assertOk()->assertJsonPath('status', 'FILED');

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/acknowledge")
        ->assertOk()->assertJsonPath('status', 'ACKNOWLEDGED');

    $this->actingAs($md)->getJson("/admin/filing/{$application->id}/status")
        ->assertOk()
        ->assertJsonPath('nipo_acknowledged_by', 'md@pdn.ac.lk')
        ->assertJsonPath('application_id', $application->id);

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/defect-sheets?sheet_number=1&description=Missing%20signature")
        ->assertOk()->assertJsonPath('sheet_number', 1);

    $this->actingAs($md)->getJson("/admin/filing/{$application->id}/defect-sheets")
        ->assertOk()->assertJsonPath('0.description', 'Missing signature');

    // DEFECT_SHEET_1 may not jump straight to GRANTED; re-acknowledge first.
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/acknowledge")
        ->assertOk()->assertJsonPath('status', 'ACKNOWLEDGED');

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/grant?patent_number=LK/PAT/2026/888")
        ->assertOk()->assertJsonPath('status', 'GRANTED');

    $record = FilingRecord::query()->sole();

    expect($record->patent_number)->toBe('LK/PAT/2026/888')
        ->and($record->defect_sheet_count)->toBe(1)
        ->and($record->granted_by)->toBe('md@pdn.ac.lk');
});

it('rejects a filed application', function () {
    $director = User::factory()->director()->create();
    $application = filingApplication();

    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/file")->assertOk();
    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/reject")
        ->assertOk()->assertJsonPath('status', 'REJECTED');

    expect(FilingRecord::query()->sole()->rejected_at)->not->toBeNull();
});

it('is idempotent when filed twice', function () {
    $director = User::factory()->director()->create();
    $application = filingApplication();

    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/file")->assertOk();
    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/file")->assertOk();

    expect(FilingRecord::query()->count())->toBe(1);
});

it('returns 404 for filing steps on an unknown application', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson('/admin/filing/missing-id/file')
        ->assertNotFound()->assertJsonPath('message', 'Application missing-id not found');
    $this->actingAs($director)->getJson('/admin/filing/missing-id/status')
        ->assertNotFound()->assertJsonPath('message', 'Application missing-id not found');
});

it('returns 404 when acknowledging an application that was never filed', function () {
    $director = User::factory()->director()->create();
    $application = filingApplication();

    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/acknowledge")
        ->assertNotFound();
});

it('returns 404 when no filing record exists yet', function () {
    $director = User::factory()->director()->create();
    $application = filingApplication();

    $this->actingAs($director)->getJson("/admin/filing/{$application->id}/status")
        ->assertNotFound()
        ->assertJsonPath('message', 'No filing record found');
});

it('rejects illegal transitions and bad defect sheets with 422', function () {
    $director = User::factory()->director()->create();
    $application = filingApplication();

    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/grant?patent_number=X")
        ->assertStatus(422);

    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/file")->assertOk();

    $this->actingAs($director)
        ->postJson("/admin/filing/{$application->id}/defect-sheets?sheet_number=4&description=x")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Invalid defect sheet number: 4 (must be 1-3)');

    $this->actingAs($director)
        ->postJson("/admin/filing/{$application->id}/defect-sheets?sheet_number=1&description=x")
        ->assertOk();

    $this->actingAs($director)
        ->postJson("/admin/filing/{$application->id}/defect-sheets?sheet_number=1&description=y")
        ->assertStatus(422)
        ->assertJsonPath('message', "Defect sheet #1 already exists for {$application->id}");

    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/grant")
        ->assertStatus(422)->assertJsonValidationErrors('patent_number');

    expect($application->refresh()->status)->toBe('DEFECT_SHEET_1');
    expect(Deadline::query()->count())->toBe(0);
});

it('gates every filing step to staff', function () {
    $application = filingApplication();
    $uri = "/admin/filing/{$application->id}/file";

    $this->postJson($uri)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($uri)->assertForbidden();
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/FilingEndpointTest.php`
Expected: FAIL with 404 on `POST /admin/filing/{id}/file` (route not defined).

- [x] **Step 3: Write minimal implementation**

`backend/app/Http/Controllers/FilingController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Filing\FilingWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller for the filing workflow. Rules (transition table,
 * defect-sheet caps, notifications, audit) live in App\Domain\Filing.
 */
final class FilingController extends Controller
{
    public function __construct(
        private readonly FilingWorkflowService $filing,
        private readonly ApplicationIntakeService $intake,
    ) {}

    public function file(Request $request, string $applicationId): JsonResponse
    {
        return response()->json($this->filing->markFiled(
            $this->intake->findOrFail($applicationId),
            $request->user(),
        ));
    }

    public function acknowledge(Request $request, string $applicationId): JsonResponse
    {
        return response()->json($this->filing->acknowledgeNipo(
            $this->intake->findOrFail($applicationId),
            $request->user(),
        ));
    }

    public function storeDefectSheet(Request $request, string $applicationId): JsonResponse
    {
        $validated = $request->validate([
            'sheet_number' => ['required', 'integer'],
            'description' => ['required', 'string'],
        ]);

        return response()->json($this->filing->recordDefectSheet(
            $this->intake->findOrFail($applicationId),
            (int) $validated['sheet_number'],
            (string) $validated['description'],
            $request->user(),
        ));
    }

    public function defectSheets(string $applicationId): JsonResponse
    {
        return response()->json($this->filing->defectSheets($this->intake->findOrFail($applicationId)));
    }

    public function grant(Request $request, string $applicationId): JsonResponse
    {
        $validated = $request->validate([
            'patent_number' => ['required', 'string', 'max:255'],
        ]);

        return response()->json($this->filing->markGranted(
            $this->intake->findOrFail($applicationId),
            (string) $validated['patent_number'],
            $request->user(),
        ));
    }

    public function reject(Request $request, string $applicationId): JsonResponse
    {
        return response()->json($this->filing->markRejected(
            $this->intake->findOrFail($applicationId),
            $request->user(),
        ));
    }

    public function status(string $applicationId): JsonResponse
    {
        $record = $this->filing->filingRecord($this->intake->findOrFail($applicationId));

        if ($record === null) {
            return response()->json(['message' => 'No filing record found'], 404);
        }

        return response()->json($record);
    }
}
```

`backend/routes/web.php` — add, with the other imports:

```php
Route::middleware(['auth', 'role:director,md'])
    ->prefix('admin/filing/{applicationId}')
    ->group(function () {
        Route::post('/file', [FilingController::class, 'file'])->name('filing.file');
        Route::post('/acknowledge', [FilingController::class, 'acknowledge'])->name('filing.acknowledge');
        Route::post('/defect-sheets', [FilingController::class, 'storeDefectSheet'])->name('filing.defect-sheets.store');
        Route::get('/defect-sheets', [FilingController::class, 'defectSheets'])->name('filing.defect-sheets.index');
        Route::post('/grant', [FilingController::class, 'grant'])->name('filing.grant');
        Route::post('/reject', [FilingController::class, 'reject'])->name('filing.reject');
        Route::get('/status', [FilingController::class, 'status'])->name('filing.status');
    });
```

`backend/bootstrap/app.php` — add two renders and their imports:

```php
$exceptions->render(
    fn (DefectSheetRejected $e) => response()->json([
        'message' => $e->getMessage(),
    ], 422),
);

$exceptions->render(
    fn (AcknowledgementWithoutFiling $e) => response()->json([
        'message' => $e->getMessage(),
    ], 404),
);
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/FilingEndpointTest.php`
Expected: PASS (8 tests).

- [x] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/FilingController.php backend/routes/web.php backend/bootstrap/app.php backend/tests/Feature/FilingEndpointTest.php
git commit -m "feat(l3): add staff-only filing workflow endpoints"
```

---

### Task 15: Journeys, isolation and the L3 endpoint matrix

**Files:**
- Test: `backend/tests/Feature/FlowJourneyTest.php`
- Test: `backend/tests/Feature/L3RbacMatrixTest.php`

**Interfaces:**
- Consumes: every route added by Tasks 4–14. No production code changes.

- [x] **Step 1: Write the failing test**

`backend/tests/Feature/FlowJourneyTest.php`:

```php
<?php

use App\Models\Application;
use App\Models\Inventor;
use App\Models\User;

it('runs the J3 rejection journey and shows the state to the inventor', function () {
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    $inventor = User::factory()->create(['email' => 'j-inv@pdn.ac.lk']);

    $applicationId = $this->actingAs($director)->postJson('/applications', [
        'title' => 'Journey Widget',
        'inventor_name' => 'Journey Inv',
        'inventor_email' => 'j-inv@pdn.ac.lk',
        'summary' => 's',
    ])->assertOk()->json('id');

    $this->actingAs($director)->postJson("/applications/{$applicationId}/status", [
        'new_status' => 'FILED',
        'reason' => 'Filed with NIPO',
    ])->assertNoContent();

    $this->actingAs($director)->postJson("/applications/{$applicationId}/status", [
        'new_status' => 'REJECTED',
        'reason' => 'Prior art',
    ])->assertNoContent();

    $listed = $this->actingAs($inventor)->getJson('/applications')->assertOk()->json();

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['id'])->toBe($applicationId)
        ->and($listed[0]['status'])->toBe('REJECTED');
});

it('runs the J6 second-user isolation journey', function () {
    $director = User::factory()->director()->create();
    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $other = User::factory()->create(['email' => 'other@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Private', 'inventor_email' => 'owner@pdn.ac.lk']);

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'FILED',
        'reason' => 'Filed with NIPO',
    ])->assertNoContent();

    expect($this->actingAs($owner)->getJson('/applications')->json())->toHaveCount(1)
        ->and($this->actingAs($other)->getJson('/applications')->json())->toBe([])
        ->and($this->actingAs($other)->getJson("/applications/{$application->id}/history")->getStatusCode())->toBe(403)
        ->and($this->actingAs($owner)->getJson("/applications/{$application->id}/history")->getStatusCode())->toBe(200);
});

it('runs the multi-user staff interplay journey', function () {
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);

    $granted = Application::query()->create(['title' => 'Grant Me', 'inventor_email' => 'granted@pdn.ac.lk']);
    $rejected = Application::query()->create(['title' => 'Reject Me', 'inventor_email' => 'rejected@pdn.ac.lk']);
    $objected = Application::query()->create(['title' => 'Object Me', 'inventor_email' => 'objected@pdn.ac.lk']);

    foreach ([$granted, $rejected, $objected] as $application) {
        Inventor::query()->create([
            'application_id' => $application->id,
            'inventor_name' => 'Inventor',
            'inventor_email' => $application->inventor_email,
        ]);
    }

    // Director prosecutes one application through office action to grant.
    $this->actingAs($director)
        ->postJson("/applications/{$granted->id}/office-actions?kind=OBJECTION&body=clarity")
        ->assertOk();
    $this->actingAs($director)->postJson("/applications/{$granted->id}/status", ['new_status' => 'FILED', 'reason' => 'Filed'])
        ->assertNoContent();
    $this->actingAs($director)->postJson("/applications/{$granted->id}/status", ['new_status' => 'ACKNOWLEDGED', 'reason' => 'Acked'])
        ->assertNoContent();
    $this->actingAs($md)->postJson("/admin/filing/{$granted->id}/grant?patent_number=LK/PAT/2026/1")
        ->assertOk()->assertJsonPath('status', 'GRANTED');

    // The MD rejects another, and the office action on the third left a deadline.
    $this->actingAs($md)->postJson("/applications/{$rejected->id}/status", ['new_status' => 'FILED', 'reason' => 'Filed'])
        ->assertNoContent();
    $this->actingAs($md)->postJson("/admin/filing/{$rejected->id}/reject")->assertOk();

    expect($this->actingAs($director)->getJson('/deadlines')->assertOk()->json())->toHaveCount(1)
        ->and($granted->refresh()->status)->toBe('GRANTED')
        ->and($rejected->refresh()->status)->toBe('REJECTED')
        ->and($objected->refresh()->status)->toBe('DRAFT');
});

it('notifies a co-inventor on every filing step', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Joint', 'inventor_email' => 'ada@pdn.ac.lk']);

    foreach (['ada@pdn.ac.lk', 'bob@pdn.ac.lk'] as $email) {
        Inventor::query()->create([
            'application_id' => $application->id,
            'inventor_name' => 'Inventor',
            'inventor_email' => $email,
        ]);
    }

    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/file")->assertOk();

    $this->assertDatabaseHas('notifications', ['recipient_email' => 'bob@pdn.ac.lk', 'subject' => 'Patent Filed']);
    expect(\App\Models\Notification::query()->count())->toBe(2);
});
```

`backend/tests/Feature/L3RbacMatrixTest.php`:

```php
<?php

use App\Models\Application;
use App\Models\User;

it('gates every L3 endpoint against guests and plain users', function () {
    $application = Application::query()->create(['title' => 'Matrix']);
    $user = User::factory()->create(['email' => 'user-a@pdn.ac.lk']);

    // [method, uri, body]
    $endpoints = [
        ['POST', '/applications', ['title' => 'T', 'inventor_name' => 'A', 'inventor_email' => 'a@pdn.ac.lk']],
        ['PATCH', "/applications/{$application->id}", ['title' => 'T']],
        ['GET', "/applications/{$application->id}/history", []],
        ['POST', "/applications/{$application->id}/deadlines?type=FILING&due_date=2027-01-01T00:00:00Z", []],
        ['GET', '/deadlines', []],
        ['POST', "/applications/{$application->id}/office-actions?kind=REJECTION&body=x", []],
        ['POST', "/admin/filing/{$application->id}/file", []],
        ['POST', "/admin/filing/{$application->id}/acknowledge", []],
        ['POST', "/admin/filing/{$application->id}/defect-sheets?sheet_number=1&description=x", []],
        ['GET', "/admin/filing/{$application->id}/defect-sheets", []],
        ['POST', "/admin/filing/{$application->id}/grant?patent_number=X", []],
        ['POST', "/admin/filing/{$application->id}/reject", []],
        ['GET', "/admin/filing/{$application->id}/status", []],
    ];

    foreach ($endpoints as [$method, $uri, $body]) {
        $this->app['auth']->forgetGuards();
        $this->json($method, $uri, $body)->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->json($method, $uri, $body)->assertForbidden();
    }
});

it('lets director and MD drive the whole application surface', function (string $state) {
    $staff = User::factory()->{$state}()->create();

    $applicationId = $this->actingAs($staff)->postJson('/applications', [
        'title' => 'Staff Driven',
        'inventor_name' => 'Ada',
        'inventor_email' => 'ada@pdn.ac.lk',
    ])->assertOk()->json('id');

    $this->actingAs($staff)->getJson('/applications')->assertOk();
    $this->actingAs($staff)->patchJson("/applications/{$applicationId}", ['title' => 'Renamed'])->assertOk();
    $this->actingAs($staff)->postJson("/applications/{$applicationId}/deadlines?type=FILING&due_date=2027-01-01T00:00:00Z")->assertOk();
    $this->actingAs($staff)->getJson('/deadlines')->assertOk();
    $this->actingAs($staff)->postJson("/applications/{$applicationId}/office-actions?kind=ALLOWANCE&body=ok")->assertOk();
    $this->actingAs($staff)->postJson("/admin/filing/{$applicationId}/file")->assertOk();
    $this->actingAs($staff)->postJson("/applications/{$applicationId}/status", ['new_status' => 'ACKNOWLEDGED', 'reason' => 'Acked'])->assertNoContent();
    $this->actingAs($staff)->postJson("/admin/filing/{$applicationId}/defect-sheets?sheet_number=1&description=x")->assertOk();
    $this->actingAs($staff)->getJson("/admin/filing/{$applicationId}/defect-sheets")->assertOk();
    $this->actingAs($staff)->postJson("/applications/{$applicationId}/status", ['new_status' => 'ACKNOWLEDGED', 'reason' => 'Acked'])->assertNoContent();
    $this->actingAs($staff)->postJson("/admin/filing/{$applicationId}/grant?patent_number=LK/PAT/2026/3")->assertOk();
    $this->actingAs($staff)->getJson("/admin/filing/{$applicationId}/status")->assertOk();
})->with(['director', 'md']);
```

- [x] **Step 2: Run the tests**

Run: `php artisan test --compact tests/Feature/FlowJourneyTest.php tests/Feature/L3RbacMatrixTest.php`
Expected: PASS (4 + 2 tests) — these are integration tests over endpoints already built in Tasks 4–14, so they should be green on the first run. A failure means a real gap in an earlier task (a wrong status code, a missing notification): fix the production code. Only if the *plan* was wrong do you change the assertion, and then you write a `Ruling:` into the ledger.

- [x] **Step 3: Commit**

```bash
git add backend/tests/Feature/FlowJourneyTest.php backend/tests/Feature/L3RbacMatrixTest.php
git commit -m "test(l3): cover rejection and isolation journeys plus the endpoint matrix"
```

---

### Task 16: Whole suite, style, graph and docs

**Files:**
- Modify: `BIGGER_PICTURE.md` (§6 L3 row and §9 status)
- Modify: this plan (tick the checkboxes)
- No production code.

**Interfaces:**
- Consumes: everything above.
- Produces: a green full suite, Pint-clean code, a refreshed knowledge graph and the roadmap marked L3-done.

- [x] **Step 1: Run the whole suite**

Run: `php artisan test --compact`
Expected: PASS with no failures — the L2 baseline of 198 tests plus the ~91 added here.

- [x] **Step 2: Format**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no style violations (it rewrites files in place if it finds any; re-run the affected tests if it did).

- [x] **Step 3: Refresh the knowledge graph**

Run (from the repository root, not `backend/`): `graphify update .`
Expected: the graph refreshes from the new `app/Domain/{Audit,Filing,Notification,Prosecution}` classes. `graphify-out/` is git-ignored, so nothing is committed from it.

- [x] **Step 4: Mark L3 done in the roadmap**

In `BIGGER_PICTURE.md`:

- §6 table, L3 row: change the tests cell from `feature tests covering journeys J1–J6` to `applications/docketing/prosecution/filing feature tests + RBAC matrix (document journeys J1/J2/J4 land in L4)`.
- §9: replace the final `- **Next: L3 (#67)** — ...` bullet with:

```markdown
- **Done: L3 (#67)** — applications create/list/patch/history with own-only
  visibility, status changes (notify + audit), docketing (deadlines),
  office actions with auto-docketed response deadlines, and the filing
  workflow (file → acknowledge → defect sheets → grant/reject), plus the
  notification and audit seams. Document journeys J1/J2/J4 land in L4.
- **Next: L4 (#71)** — master-key vault, encrypted document store, upload
  and download rules, missing-documents worklist, request-document.
```

- Tick every `- [ ]` step box in this plan file.

- [x] **Step 5: Commit**

```bash
git add BIGGER_PICTURE.md docs/superpowers/plans/2026-10-17-l3-core-flows.md
git commit -m "docs(l3): mark core flows layer done, refresh graph"
```

