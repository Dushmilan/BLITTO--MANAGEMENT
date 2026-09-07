# BLITTO Patent Management System -- Domain Context

## Domain Glossary

**Patent Application** -- A legal filing seeking patent protection. Has a lifecycle: DRAFT > FILED > ACKNOWLEDGED > EXAMINATION > GRANTED/REJECTED > MAINTENANCE.

**Prior Art** -- Any evidence that an invention is already known (prior patents, publications, public use). Must be cited during filing (IDS -- Information Disclosure Statement).

**Office Action** -- Official communication from the patent office (USPTO/EPO) containing rejections, objections, or allowances.

**Inventor** -- Natural person who conceived the invention. Has moral rights but typically assigns rights to an assignee (company).

**Assignee / Applicant** -- Entity (usually a company) that owns the patent application. Holds the rights and pays fees.

**Attorney / Agent** -- Registered practitioner who prosecutes the application before the patent office. Has power of attorney.

**Paralegal** -- Prepares forms, tracks office actions.

**Portfolio** -- Collection of patent applications/patents owned by an assignee. Viewed at the portfolio level for reporting and maintenance fee tracking.

**Document / Artifact** -- Any file attached to a patent application: specification, claims, drawings, office actions, responses, assignments, IDS forms, POA forms.

**Maintenance Fee** -- Periodic fees (3.5, 7.5, 11.5 years after grant in US) to keep a granted patent in force.

**Priority Date** -- The earliest filing date claimed (provisional or PCT). Determines prior art cutoff.

**Family** -- Set of patent applications across jurisdictions sharing a common priority document.

## Domain Invariants

1. **Deadline integrity** -- A deadline missed = application abandoned (with rare exceptions). No soft deadlines.
2. **Role separation** -- Directors and MDs prosecute and docket; Users disclose; MD manages users. No role can act for another.
3. **Document immutability** -- Once filed with the patent office, a document cannot be modified. Only new documents can be added.
4. **Single source of truth for deadlines** -- The docketing module is the sole authority on deadlines. No duplicate tracking elsewhere.
5. **Audit trail** -- Every state change on an application, deadline, or document is logged with actor, timestamp, and reason.
6. **Confidentiality** -- Users see only their own applications. Directors and MDs share the full docket and portfolio.

## User Roles (RBAC)

| Role | Permissions |
|------|-------------|
| **MD** | User management, role assignment, system config, full portfolio view, audit logs |
| **Director** | Same use case as MD except user management: intake, docketing, uploads, office actions, status changes, portfolio view |
| **User** | View own applications, review status, download granted patents, receive notifications |

## Key Workflows

1. **Intake** -- User submits disclosure > Director creates application shell > Director/MD reviews > File provisional/non-provisional
2. **Prosecution** -- Office action received > Director dockets deadline > Director prepares response > File response > Repeat
3. **Grant & Maintenance** -- Patent grants > Maintenance fee deadlines docketed > Fees paid > Patent maintained
4. **Reporting** -- Portfolio dashboards, deadline reports, director workload, maintenance fee forecasts

## External Dependencies (Dependency Categories)

| Dependency | Category | Notes |
|------------|----------|-------|
| PostgreSQL (via Prisma) | Local-substitutable (PGlite for tests) | Primary data store |
| S3-compatible storage (AWS S3 / MinIO) | Ports & adapters | Document storage -- production uses S3, tests use in-memory adapter |
| Email provider (SendGrid / Resend / SMTP) | True external (mock adapter) | Notifications -- tests use mock adapter |
| Patent Office APIs (USPTO PAIR, EPO OPS) | True external (mock adapter) | Future integration |
| NextAuth.js providers (OAuth, credentials) | Local-substitutable (test adapter) | Authentication -- tests use test adapter |

## Non-Functional Requirements

- **Auditability** -- Every mutation is traceable to an actor and timestamp
- **Latency** -- Dashboard loads < 2s
- **Testability** -- Business logic tested through module interfaces, not through HTTP
- **Deployability** -- Single Next.js deployment (Vercel); DB migrations via Prisma Migrate
