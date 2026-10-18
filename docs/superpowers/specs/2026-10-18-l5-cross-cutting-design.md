# BLITTO PATENT DATABASE — L5 cross-cutting design

> Date: 2026-10-18 · Status: draft for review
> Layer: L5 of the Laravel rewrite (`BIGGER_PICTURE.md` §6) · Issue:
> [#69](https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/69) (part of
> [#65](https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/65))
> Branch: `feat/l5-cross-cutting`, stacked on the L4 head (`abc176f`, PR #74)
> Predecessors: the migration design
> (`docs/superpowers/specs/2026-10-09-blitto-patent-database-laravel-migration-design.md`,
> §5 schema, §7 domain behaviour, §9 testing), `docs/adr/0004-documents-stored-in-the-clear.md`,
> and the L1–L4 plans.
> Reference behaviour: git tag `pre-laravel-final-state` (read with
> `git show pre-laravel-final-state:<path>`).

## 1. Purpose and success criteria

L5 makes the platform's cross-cutting concerns real: notifications leave the
application as mail, every mutation is traceable to an actor, the portfolio can
be reported on, stalled NIPO filings chase themselves, and a demo dataset can be
restored from the reference snapshot. It adds no UI (L6) and no crypto (deferred
by ADR 0004).

Success looks like:

- A notification written anywhere in the domain is persisted as a row **and**
  handed to a mail seam; tests assert delivery through our own adapter, not
  through the framework's fake.
- `php artisan db:seed` works (it throws today — see §2) and produces the
  reference demo state with bcrypt-hashed passwords.
- `GET /analytics/portfolio` and `GET /analytics/deadlines` answer for
  `director`/`md` and refuse everyone else.
- `php artisan nipo:follow-up` reminds the staff about filed-but-unacknowledged
  applications, and is registered in the scheduler at the configured interval.
- Every mutating action leaves an audit row with an actor, an application where
  one applies, and an IP where one applies.
- The full Pest suite is green (baseline 350 tests / 1011 assertions) and
  `vendor/bin/pint --dirty` is clean.

## 2. What exists today (evidence, 2026-10-18)

| Area | Present | Absent |
|---|---|---|
| Notifications | `notifications` table; `NotificationService` persisting rows with status subject/body templates that already match the reference verbatim, including the `GRANTED — download ready` em-dash | any Mailable, any `Mail::` call, any mail view, any delivery seam |
| Audit | `AuditRecorder`, `audit_logs` (uuid id, `user_id` string, `application_id` uuid FK nullOnDelete, `action`, `timestamp`, `ip_address`), 11 action strings across intake/docketing/status/prosecution/filing/documents/downloads | IP on the five filing steps; rows for registration, login, logout; a `grant_without_document` row on the filing path (the reference wrote one) |
| Analytics | nothing | both endpoints, both computations |
| Scheduler | `config('blitto.nipo_follow_up_interval_hours')` defined (default 24) and asserted in `BlittoConfigTest`; `jobs` table from L0 | any scheduled task, any `app/Console/` class, any use of that config key |
| Seeder | `database/seeders/DatabaseSeeder.php` | demo data of any kind; the stock seeder creates `Test User` with a `name` key the `users` table does not have, so `db:seed` **fails today** |
| Mail config | stock `config/mail.php`, `config/queue.php`; `.env.example` `MAIL_MAILER=log`; `phpunit.xml` `MAIL_MAILER=array` | any code that uses them |

Reference behaviour worth keeping in view (all read from the tag):

- `app/adapters/email/local/__init__.py`'s `LocalEmailProvider` printed to
  stdout and had **no call site** — the "mock driver" parity target is a
  conceptual seam, not a working transport.
- Reference status templates, the GRANTED-without-document variant, the NIPO
  reminder wording, the analytics shapes and the two reference warts
  (`deadline_report.by_type` counting all deadlines; no scheduler dedup) are
  quoted where they are used below.

## 3. Decisions (2026-10-18)

1. **Mail is a domain port with a Laravel-backed adapter.** A `MailChannel` port
   owns delivery; the production adapter delegates to Laravel's mail layer, and
   an in-memory adapter serves tests. Rejected: calling `Mail::` from the domain
   (couples the domain to the framework and leaves us no seam), and queued
   delivery (a worker to run, for a mock transport that costs microseconds).
2. **Notification scope is delivery plus one behavioural gap.** L5 wires the
   seam and fixes the GRANTED-without-document wording and admin warning on the
   generic status route. No notification read API and no ad-hoc notify endpoints
   (they arrive with their UI, or not at all).
3. **Where the reference is arguably wrong, fix the data shape only.**
   `deadline_report.by_type` counts OPEN deadlines only, and status/type maps are
   emitted in enum declaration order. The scheduler keeps the reference's
   re-notify-every-run behaviour: a daily nudge until someone acknowledges NIPO
   is defensible, and acknowledgement is the natural stop condition.
4. **The demo seeder reads the snapshot.** `dev-data/seed_snapshot.json` is
   imported as-is (its plaintext passwords bcrypt-hashed, its base64 document
   contents written through the `DocumentStore` port), idempotently, with
   `blitto.demo_inventor*` overriding the two inventor accounts when configured.
   Rejected: porting `scripts/seed.py`'s generated narrative (richer, but not
   the data the issue names), and doing both.
5. **Audit closes its write gaps only.** Filing steps gain the IP they were
   missing; registration, login and logout gain rows. No schema change
   (`reason` stays in `status_history`, per the L1/L2 ruling), no read endpoint.
6. **One plan, six task groups**: audit gaps → mail seam → grant side effects →
   analytics → scheduler → seeder.

## 4. The mail seam

### 4.1 Port and adapters

```php
namespace App\Domain\Notification;

interface MailChannel
{
    public function send(string $to, string $subject, string $body): void;
}
```

The signature is the reference's `EmailProviderAdapter.send(to, subject, body)`
one for one, so the seam is recognisably the thing being ported.

| Class | Role |
|---|---|
| `App\Domain\Notification\MailChannel` | the port |
| `App\Domain\Notification\LaravelMailChannel` | production adapter: `Mail::to($to)->send(new NotificationMail($subject, $body))` |
| `App\Domain\Notification\ArrayMailChannel` | test adapter: records `{to, subject, body}` in memory, exposes `messages()`, `sentTo(string $email)`, `lastMessage()` |
| `App\Domain\Notification\UnsupportedMailChannel` | raised when `blitto.mail_channel` names an adapter that does not exist (the L4 `UnsupportedDocumentStore` pattern) |
| `App\Mail\NotificationMail` | the only Mailable; `envelope()` carries the subject, `content()` renders `resources/views/mail/notification.blade.php` |
| `resources/views/mail/notification.blade.php` | escaped body with line breaks preserved |

The transport stays Laravel's business: `MAIL_MAILER=log` locally is the
"local/mock driver" (parity with `LocalEmailProvider`'s non-delivery), `smtp`
in production, one config line and no code branch. The domain never touches a
facade.

### 4.2 Delivery policy

`NotificationService::send()` becomes:

1. persist the `notifications` row (the durable record — unchanged behaviour);
2. hand `(recipient, subject, body)` to the bound `MailChannel` inside a
   `try/catch (Throwable)`;
3. on failure, `Log::warning('Notification mail delivery failed', [...])` and
   continue.

Mail can never fail a business action, and a mail the `log` driver swallowed is
still visible as a row. The row is written **before** delivery so a failure
cannot lose the record. The port is documented as allowed to throw; the
swallow-and-log policy lives in the service, so every present and future send
path inherits it.

### 4.3 Binding and configuration

`config/blitto.php` gains:

```php
// Mail delivery adapter: "laravel" (delegates to Laravel's mailer; MAIL_MAILER
// picks the transport) or "array" (in-memory, the test adapter).
'mail_channel' => env('BLITTODB_MAIL_CHANNEL', 'laravel'),
```

`AppServiceProvider::register()` binds `MailChannel::class` with a `match` on
that key, following the `DocumentStore` binding shape from L4 (`laravel` →
`LaravelMailChannel`, `array` → `ArrayMailChannel`, anything else → a loud
`UnsupportedMailChannel`). `phpunit.xml` sets `BLITTODB_MAIL_CHANNEL=array`.
`.env.example` documents the key commented-out, in the same block as the other
`BLITTODB_*` keys, and its `MAIL_FROM_ADDRESS` becomes `no-reply@pdn.ac.lk`.

### 4.4 Templates, kinds, and the GRANTED pending variant

Existing templates stay untouched: `STATUS_SUBJECTS`/`STATUS_BODIES` (seven
statuses plus the generic fallback), the filing-step subjects ("Patent Filed",
"Acknowledged", "Patent Received", "Rejected", "Defect Sheet Received"), the
`download_ready` wording, the `document_request` wording, and L4's
`grant_without_document` wording.

Added, verbatim from the reference:

| Constant | Value |
|---|---|
| pending subject | `Patent {ref} GRANTED — document pending` |
| pending body | `Good news — patent {ref} is now GRANTED. The document is pending upload by BLITTO; you can request it from your portal and we will notify you when it is ready to download.` |

```php
public function notifyStatusChange(
    Application $application,
    ApplicationStatus $status,
    bool $documentAvailable = true,
): int
```

`GRANTED` with `$documentAvailable === false` sends the pending pair (kind
`status_change`); every other combination keeps its current wording. Callers
that do not care keep calling with two arguments.

Kinds in use after L5: `status_change` (default), `filing`, `download_ready`,
`document_request`, `grant_without_document`, and `nipo_follow_up` (§8).

### 4.5 Deliberately not built

`GET /notifications`, `POST /notify/status-change`, `POST /applications/{id}/notify`,
per-kind Mailable classes, queues, retries and bounce handling. The reference's
one-open-request rule for `request-document` already lives in L4's audit-based
check and is unchanged.

## 5. Audit: closing the write gaps

| Gap | Fix |
|---|---|
| The five `FilingWorkflowService` steps (`markFiled`, `acknowledgeNipo`, `markGranted`, `recordDefectSheet`, `markRejected`) write audit rows without an IP | each gains a trailing `?string $ipAddress = null` and passes it to its existing `audit->record(...)`; `FilingController` supplies `$request->ip()`. Trailing and optional, so direct-service tests keep compiling |
| User creation leaves no trace | `UserDirectory::register()` gains two trailing optional parameters, `?User $actor = null, ?string $ipAddress = null`, and records `create_user` — it is the single creation entry point, so neither `RegisterController` nor `BootstrapAdmin` can bypass it. `RegisterController` passes the acting user and the IP (named arguments); `BootstrapAdmin` passes neither, so the row records that the system created the account (at most one, on the request that creates the first admin). The demo seeder (§9) deliberately reuses this path and inherits the rows it produces |
| Login/permission-less logout leave no trace | `Auth\LoginController` and `Auth\LogoutController` inject `AuditRecorder` and record `auth:login` / `auth:logout` with the authenticated user and `$request->ip()`. The controller *is* the session authority here, so this is a deliberate exception to "services audit" rather than a new `SessionService` for a two-line side effect |

Action names follow the established colon-namespacing. Full set after L5:
`create_application`, `update_application`, `add_deadline:{TYPE}`,
`status_change:{STATUS}`, `receive_office_action:{KIND}`, `store_document`,
`delete_document`, `download_granted_patent`, `document_requested`,
`filing:mark_filed`, `filing:acknowledge_nipo`, `filing:granted`,
`filing:rejected`, `filing:defect_sheet`, `grant_without_document`,
`create_user`, `auth:login`, `auth:logout`.

Only **successful** login/logout attempts are recorded. Failed attempts already
increment `AttemptThrottle`, and auditing them would let an unauthenticated
caller grow `audit_logs` as fast as the throttle allows.

Unchanged and intentional: no `reason`/notes column on `audit_logs` (the L1/L2
ruling keeps prose in `status_history.reason`), no audit for reads or for the
NIPO reminder, no audit read endpoint.

## 6. Grant side effects get one home

L4 grew the grant-without-document behaviour inside the filing workflow. L5
extracts it into `App\Domain\Vault\GrantWarning`, so both paths that can grant a
patent share one implementation:

```php
final class GrantWarning
{
    public function hasDocument(Application $application): bool;          // DocumentService::hasDocuments
    public function warning(Application $application): ?string;           // L4's message, unchanged
    public function announce(Application $application, User $actor, ?string $ipAddress): ?string;  // warning to the admin inbox + `grant_without_document` audit row
}
```

- **Filing path** (`FilingWorkflowService::markGranted`): behaviour unchanged for
  the inventor ("Patent Received") and for the grant response's `warning` field,
  **plus** the `grant_without_document` audit row the reference wrote and L4
  missed.
- **Status route** (`StatusChangeService::change`): when the new status is
  `GRANTED`, the inventor mail uses the pending pair (§4.4) whenever no document
  exists, and `announce()` fires the same admin warning and audit.

Today the status route tells an inventor "download ready" for a document that
does not exist; that is the defect this section closes.

Not unified: a *successful* grant is still worded differently on the two paths
("Patent Received" from the filing chain, "GRANTED — download ready" from the
status route). That is existing reference parity and stays.

## 7. Analytics

`App\Domain\Analytics\PortfolioAnalytics` (a plain deep module — there is no
external dependency to abstract, matching `DocumentService::missingGrantedDocuments`
rather than inventing a port):

```php
public function portfolioSummary(): array;  // ['total_applications' => int, 'by_status' => ['DRAFT' => int, ...]]
public function deadlineReport(): array;    // ['open_deadlines' => int, 'by_type' => ['RESPONSE' => int, ...]]
```

Rules:

- `total_applications` counts **every** application, whatever its status (no
  "active" concept — reference parity).
- `by_status` groups by current status, keyed in `ApplicationStatus` declaration
  order, omitting statuses with zero applications (reference parity, plus the
  ordering fix).
- `open_deadlines` counts deadlines whose status is `OPEN`.
- `by_type` counts **OPEN deadlines only** (the fix; the reference counted every
  deadline, so the two numbers in one payload disagreed), keyed in
  `DeadlineType` declaration order, omitting zero-count types.

`App\Http\Controllers\AnalyticsController` returns `response()->json(...)` and
delegates; routes are `GET /analytics/portfolio` and `GET /analytics/deadlines`
under `auth` and `role:director,md`. Response examples:

```json
{"total_applications": 3, "by_status": {"DRAFT": 1, "GRANTED": 2}}
{"open_deadlines": 2, "by_type": {"RESPONSE": 1, "MAINTENANCE_FEE": 1}}
```

No query parameters, no date windows, no pagination, no `open_deadlines`-by-type
inconsistency.

## 8. NIPO follow-up scheduler

`App\Domain\Filing\NipoFollowUp` owns the rule:

- `stalled(): Collection` — applications that have a filing record with
  `nipo_acknowledged_at IS NULL` and whose status is still `FILED`.
- `remind(): int` — for each stalled application, notify every `md` and
  `director` user, returning the number of notifications written. Subject and
  body follow the reference:

  | Field | Value |
  |---|---|
  | subject | `NIPO Follow-up Required: {title}` |
  | body | `Patent {title} ({application_number or first 8 of id}) was filed on {filed_date:%Y-%m-%d} but has not yet been acknowledged by NIPO. Please contact NIPO for follow-up.` |
  | kind | `nipo_follow_up` (the reference left it at the `status_change` default; a distinct event deserves a distinct kind, and L6 will want to filter it) |

`App\Console\Commands\NipoFollowUpCommand` (`nipo:follow-up`, the project's first
command) is a thin shell over `remind()`, printing the count. `routes/console.php`
registers it:

```php
$hours = max(1, (int) config('blitto.nipo_follow_up_interval_hours'));

Schedule::command('nipo:follow-up')->cron("0 */{$hours} * * *")->withoutOverlapping();
```

so the interval config finally drives something: 24 becomes a daily run with no
host-cron change beyond `schedule:run`. If the cron expression validator rejects
a step of 24 in the hour field, the fallback is `0 0 * * *` for any value ≥ 24;
the registered expression the code chooses is what the test pins.

No audit row: the reminder changes no state, so it is outside the audit scope in
§5. Re-running re-notifies (decision 3) — the acknowledge endpoint remains the
stop condition.

## 9. Demo data seeder

`blitto.demo_snapshot_path` (`BLITTODB_DEMO_SNAPSHOT_PATH`, default
`../dev-data/seed_snapshot.json`, i.e. the repo's file from inside `backend/`)
points at the reference snapshot. `database/seeders/DemoDataSeeder.php` imports
it; `DatabaseSeeder` calls it instead of creating the `Test User` that throws
today. A missing or unreadable snapshot aborts with a clear message — the file's
presence is the "this is a development box" signal, so there is no separate
opt-in flag.

Mapping (the snapshot's collections are dicts keyed by uuid, except
`notification.sent`, which is a list, and `application_intake.history`, a dict of
lists):

| Snapshot | Table | Notes |
|---|---|---|
| `authorization.users` + `authorization.passwords` | `users` | created through `UserDirectory::register()` so the plaintexts (`mdpw-123`, `dirpw-123`, `userapw-123`, `userbpw-123`) become bcrypt `password_hash` values; matched by email on re-run. Because this is the real creation path, the first run also writes one `create_user` audit row per account (§5) |
| `application_intake.store` | `applications` + `inventors` | upserted by the snapshot's uuid; the nested `inventors[]` list becomes `inventors` rows so `NotificationService::recipients()` works |
| `application_intake.history` | `status_history` | the snapshot predates `reason`, which is NOT NULL with no default, so the importer writes an empty string (a one-line change to a backfill sentence if the demo reads poorly) |
| `docketing.deadlines` | `deadlines` | `created_at`, `due_date` and `status` ported as-is |
| `notification.sent` | `notifications` | **not** re-delivered by mail: they are history, not new events |
| `audit.log` | `audit_logs` | `user_id` is the snapshot's short value (`"md"`), stored as-is in the string column |
| `document_vault.documents` + `contents` | `documents` + vault disk | base64 decoded and written through the `DocumentStore` port; the snapshot's `mem:<uuid>` refs cannot be reused (the store only accepts a bare uuid), so real refs are minted; skipped when the row exists and its bytes still resolve |
| `filing_workflow.records`, `prosecution.*` | `filing_records`, `office_actions`, `responses` | empty in the shipped snapshot, so the seeder deliberately has **no** import code for them and ignores sections it does not know — restore them only when a demo dataset actually contains filings |
| `version` | ignored | must equal `1`; anything else aborts |

Restored rows keep their snapshot timestamps. The models do not expose
`created_at`/`updated_at`/`sent_at`/`uploaded_at`/`timestamp` as fillable, so the
importer assigns them explicitly before saving instead of relying on `create()`,
and Eloquent leaves an already-dirty timestamp alone.

Expected result for the shipped snapshot: 4 users, 3 applications, 3 inventor
rows, 2 status-history rows, 2 deadlines, 5 notifications, 2 documents, 0 filing
records — plus the 2 restored audit rows and the 4 `create_user` rows from the
one-time account creation, so a second run leaves every count unchanged.

**Idempotent**: users matched by email, every uuid-keyed row upserted by its
snapshot id, documents skipped once present and readable. Running the seeder twice
leaves the row counts identical.

**Demo inventor override**: when `blitto.demo_inventor1_email` is set, the
snapshot's first `user` account takes that email and `blitto.demo_inventor1_password`
(and `2` likewise), so seeded applications belong to the configured demo
inventor — the reference's `test_seed_demo.py` expectation.
`blitto.demo_inventor*_name` has nowhere to live (the `users` table has no name
column) other than the applications' denormalised `inventor_name`; the seeder
writes it there and the config keys are otherwise inert.

## 10. Config, CI and docs

- `config/blitto.php`: add `mail_channel`, `demo_snapshot_path`.
- `phpunit.xml`: `BLITTODB_MAIL_CHANNEL=array`.
- `.env.example`: document `BLITTODB_MAIL_CHANNEL` and `BLITTODB_DEMO_SNAPSHOT_PATH`
  in the commented-out `BLITTODB_*` block (defaults live in `config/blitto.php`
  so the parity suite runs against a clean environment), and set
  `MAIL_FROM_ADDRESS="no-reply@pdn.ac.lk"`.
- `BIGGER_PICTURE.md`: L5 row and the §9 status/next-move entry.
- `CONTEXT.md`: the dependency table still names `app/adapters/email/` and the
  Python-era document storage default; point it at the Laravel seams (the L7
  docs rewrite handles the rest).
- `docs/adr/0005-mail-seam-and-notification-delivery.md`: the new seam, the
  best-effort delivery ruling, and the two deliberate divergences from the
  reference (analytics `by_type`, scheduler kind).

## 11. Testing strategy

Everything is Pest, `it('...', function () {...})`, `RefreshDatabase` via
`tests/Pest.php`, one test at a time (TDD), through public interfaces.

| Area | Cases |
|---|---|
| Mail seam | delivery through `ArrayMailChannel` (recipient/subject/body equal to the persisted row); a channel that throws still persists the row, logs a warning and lets the request succeed; `LaravelMailChannel` dispatches a `NotificationMail` under `Mail::fake()`; `blitto.mail_channel` default and env override; an unsupported value fails loudly |
| Audit | each filing step's row carries the IP; `create_user` for MD-created, self-registered and bootstrap users; `auth:login` / `auth:logout` rows with actor and IP; a failed login writes no row |
| Grant side effects | status route → GRANTED with no document: pending wording, one `grant_without_document` notification, one `grant_without_document` audit row; with a document: download wording, no warning, no row; the filing path's existing tests keep passing and now also assert the audit row |
| Analytics | empty portfolio; mixed statuses; every status present; a transition moving the count; `by_type` excluding met/missed deadlines (present in the fixture to prove it); endpoint suite: guest 401, `user` 403, `director` 200, `md` 200, exact JSON shape |
| Scheduler | stalled filing → one notification per md/director; acknowledged → none; status no longer FILED → none; no staff users → clean no-op; two runs re-notify (pinned); the command is registered with the expected cron expression and `withoutOverlapping` |
| Seeder | seeding a fixture snapshot through `$this->seed(DemoDataSeeder::class)` produces the expected counts; `Hash::check('mdpw-123', ...)`; document bytes readable through the store; a second run changes nothing; the demo-inventor override relocates the applications; the real `dev-data/seed_snapshot.json` still imports |

The reference pytest suites inform these cases without being ported line by
line: `tests/test_notification_analytics.py`, `test_notification_extended.py`,
`test_notification_templates.py`, `test_portfolio_analytics_extended.py`,
`test_seed_demo.py`.

## 12. Out of scope

- The notification read API and the ad-hoc notify endpoints (§4.5).
- An audit read endpoint, and an `audit_logs.reason` column.
- Queued/retried mail, bounce handling, real SMTP credentials.
- Analytics beyond the two portfolio/deadline payloads: no date windows, no
  per-inventor or per-technology breakdowns, no export.
- Documents encryption and the vault unlock gate (ADR 0004), the L6 UI, and the
  L7 cutover/rename (`BIGGER_PICTURE.md` §6).

## 13. Risks and open points

- **Cron step of 24.** `0 */24 * * *` is intended to mean "once, at midnight";
  if the validator rejects it, the scheduled expression falls back to `0 0 * * *`
  for any interval ≥ 24 (§8). The test pins whichever the code registers, so
  this cannot drift silently.
- **The snapshot is a state dump, not seed definitions.** Its timestamps are
  absolute (2026-09-09) and two of its three applications share a GRANTED status
  with no application numbers, so the demo portfolio is thin. That is the data
  the issue names; L6 may want a richer fixture later.
- **`DatabaseSeeder` changes behaviour.** Today `db:seed` throws; after L5 it
  seeds the demo snapshot and nothing else. No test depends on the current
  behaviour.
- **Best-effort mail can hide misconfiguration.** With `MAIL_MAILER=log` and a
  swallowed failure, a broken production mailer shows up only as a log warning —
  the durable `notifications` rows are the safety net, and ADR-0005 records the
  trade-off.
