# ADR 0005: The mail seam and best-effort notification delivery

## Status
Accepted

## Context
The reference implementation shipped an email adapter that nothing called:
`app/adapters/email/local/__init__.py` printed to stdout and had zero call
sites, so every "notification" in the old system was a row in the notification
module and a print statement. The migration design (§7) asked for
"notifications through Laravel's mail layer with a local/mock driver (parity
with `LocalEmailProvider`)", and CONTEXT.md promised an email-provider seam
whose tests use a mock adapter. L5 had to decide what parity means when the
thing being ported never delivered anything.

## Decision
`App\Domain\Notification\MailChannel` is a published port with the reference's
own signature — `send(string $to, string $subject, string $body): void` — and
two adapters: `LaravelMailChannel`, which hands the message to Laravel's mailer
so the transport stays a `MAIL_MAILER` concern (`log` locally, `smtp` in
production), and `ArrayMailChannel`, an in-memory recorder bound when
`blitto.mail_channel=array` so tests assert delivery through our seam rather
than the framework's fake. `App\Mail\NotificationMail` is the single Mailable;
there is no per-kind class because the subject and body are already composed by
`NotificationService`.

Delivery is best effort. `NotificationService::send()` writes the
`notifications` row first — the row is the durable record — then hands the
message to the port inside a `try/catch (Throwable)` that logs a warning and
continues. A broken mailer can never fail the action that produced the
notification.

Three deliberate divergences from the reference are recorded here so they are
not mistaken for drift:

- `PortfolioAnalytics::deadlineReport()`'s `by_type` counts **OPEN** deadlines
  only. The reference counted every deadline, so `open_deadlines` and `by_type`
  in the same payload disagreed.
- The NIPO follow-up reminder uses the notification kind `nipo_follow_up`
  instead of the reference's default `status_change`, so L6 can filter it.
- The reminder is **not** deduplicated: every run re-notifies each MD and
  director for each still-unacknowledged filing, exactly as the reference did.
  A daily nudge until someone records the acknowledgement is the point, and the
  acknowledge endpoint is the stop condition.

Two further decisions belong to this layer: audit rows now cover the five filing
steps' client IP and the mutations that previously left no trace at all
(`create_user` through `UserDirectory::register()`, and `auth:login` /
`auth:logout` recorded by the session controllers themselves — the deliberate
exception to "services audit", because there is no session domain service).

## Consequences
- Notification confidentiality and delivery depend on the configured mailer;
  with `MAIL_MAILER=log` a message is written to the application log. The
  `notifications` table is the safety net: every message is queryable even when
  delivery failed.
- A misconfigured production mailer surfaces as log warnings, not as failing
  requests. Monitoring must watch the log (or the table) rather than HTTP error
  rates.
- Swapping transports is a configuration change, plus at most a new adapter; no
  domain code changes. Queued delivery remains open — the seam is the place it
  would attach.
- `by_type` no longer matches the reference's numbers for an identical database,
  and the reminder's kind differs; a consumer porting expectations from the old
  system must be told.
