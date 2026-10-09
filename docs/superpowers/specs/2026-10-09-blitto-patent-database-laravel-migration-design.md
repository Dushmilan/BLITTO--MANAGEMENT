# BLITTO PATENT DATABASE — Laravel Migration Design

- Date: 2026-10-09
- Status: Approved (design reviewed in chat; awaiting spec review)
- Tracking issue: https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/65

## 1. Purpose

Rename the project to **BLITTO PATENT DATABASE** and migrate the backend from
FastAPI (Python) to Laravel (PHP), replacing the React SPA with server-rendered
Blade/Livewire. The migration proceeds layer by layer (Approach A), with the
existing 310-test pytest suite serving as the behavioral spec until the cutover.

Success looks like:

- A Laravel application in `backend/` that reproduces the current system's
  behavior, security invariants, and RBAC matrix — proven by ported tests.
- All branding, env vars, package names, and the GitHub repo renamed to
  BLITTO PATENT DATABASE / `blitto-patent-database`.
- The Python backend and React frontend deleted only after the Laravel suite
  is green and the UI is verified.

## 2. Decisions

| Decision | Choice |
|---|---|
| Frontend | Blade + Livewire 4 (React SPA retired at L7) |
| Database | SQLite via Eloquent + migrations |
| Auth | Laravel session auth (replaces stateless JWT + refresh flow) |
| Env var prefix | `BLITTODB_` (renamed from `BLITTO_`) |
| Test framework | Pest (PHP), porting the pytest suite |
| Migration strategy | Approach A — in-repo parallel build, backend layers first |
| Laravel version | 13.x (current stable; supported to 2028) |
| PHP/Composer install | Deferred — prerequisite of L0, not done in advance |
| Repo rename | GitHub `BLITTO--MANAGEMENT` → `blitto-patent-database` at L7 |
| Display name | "BLITTO Patent Management System" → "BLITTO PATENT DATABASE" |

## 3. Approach

The FastAPI app stays in the repo untouched as the **parity oracle**: its 310
pytest tests define correct behavior. The Laravel app grows beside it in
`backend/`; each layer ships with its ported tests green before the next layer
starts. The old app runs on `:8000`, Laravel on `:8001` during development.

| Layer | Deliverable | Tests ported |
|---|---|---|
| **L0 Foundation** | PHP 8.3 + Composer install (deferred to start of L0), Laravel 13 skeleton in `backend/`, app name "BLITTO PATENT DATABASE", `BLITTODB_` config prefix, SQLite, full schema designed once from all Pydantic models, CI job | scaffold/health check |
| **L1 Domain** | Migrations + Eloquent models + DRAFT→…→GRANTED lifecycle transition table + 6 domain invariants from CONTEXT.md | lifecycle/model tests |
| **L2 Auth & RBAC** | Session auth, 3 roles (user/director/md), middleware gates mirroring the endpoint RBAC matrix, email-domain policy, login + vault throttling, bootstrap admin | auth edge + RBAC matrix + security suites |
| **L3 Core flows** | Applications CRUD, history, status changes, docketing, office actions, filing workflow (file→acknowledge→defect sheets→grant/reject) | journeys + flow edge |
| **L4 Document vault** | Master-key unlock + TTL, encrypted store (same file format as `PKIEncryptedStore`), upload/download rules, missing-documents worklist, request-document | vault + upload security |
| **L5 Cross-cutting** | Notifications (mock/local mail driver), audit trail, analytics reports, NIPO follow-up as Laravel scheduled task, demo seeder from `dev-data/` (bcrypt'ing plaintext passwords) | notification/analytics/seed |
| **L6 UI** | Blade/Livewire screens replacing all React pages, styled with existing `DESIGN.md` tokens (Tailwind) | Pest feature + Dusk smoke tests |
| **L7 Cutover** | Delete `app/`, `frontend/`, Python tests, `pyproject.toml`, egg-info; rename GitHub repo + local folder; rewrite docs; regenerate graphify | full suite green |

## 4. Architecture & toolchain

- **Stack:** Laravel 13, PHP 8.3+, Livewire 4, Blade, Tailwind (existing
  `DESIGN.md` token spec), SQLite, Pest.
- **L0 prerequisite:** install PHP 8.3 + Composer + extensions (`sqlite3`,
  `mbstring`, `openssl`) via `scripts/setup-php.sh` so CI and local match.
  **Deferred — not installed until L0 execution begins.**
- **Location:** `backend/` coexists with Python `app/` until L7.
- **Structure:** modular monolith preserved. Domain logic lives in
  `app/Domain/{Lifecycle,Docketing,Filing,Vault,...}/` classes; controllers
  stay thin (consistent with Rules.md deep-module rules). Eloquent models are
  persistence-only; no business logic in controllers or models.

## 5. Data model (designed once, at L0)

12 tables derived from the Pydantic models:

`users` (role enum user/director/md, user_code, bcrypt password),
`applications`, `inventors`, `status_history`, `deadlines`, `office_actions`,
`responses`, `documents` (metadata only; encrypted bytes on disk),
`filing_records` (1:1 application), `defect_sheets`, `notifications`,
`audit_logs`.

All foreign-key constrained with indexes — real referential integrity replaces
the in-memory dict stores. Seeders replicate `dev-data/*.json`, bcrypt'ing the
plaintext demo passwords found there.

## 6. Auth & security

- Laravel cookie sessions + CSRF; JWT/refresh/`blitto_token` machinery removed.
- Middleware `role:director|md` gates mirroring the endpoint RBAC matrix;
  `test_rbac_matrix.py` becomes the middleware test.
- Email-domain policy (`pdn.ac.lk` + subdomains, configurable) as a
  registration validation rule (`BLITTODB_ALLOWED_EMAIL_DOMAINS`).
- Login throttling (5/10 min/account) and vault-unlock throttling
  (5/10 min/IP) via Laravel's built-in `RateLimiter`.
- Bootstrap admin from `BLITTODB_BOOTSTRAP_ADMIN_*` env vars; refuse default
  secrets outside local environment (parity with `ensure_production_ready`).
- Vault unlock: master key from `BLITTODB_VAULT_MASTER_KEY`, unlock state in
  session/cache with TTL parity (12 h), re-lock returns 423.

## 7. Domain behavior

- Lifecycle `DRAFT → FILED → {PUBLISHED, ACKNOWLEDGED, EXAMINATION,
  DEFECT_SHEET_1..3, REJECTED} → GRANTED → MAINTENANCE` enforced by a
  transition table in a `LifecycleService`; illegal transitions → HTTP 422.
- Encrypted document store ported from `PKIEncryptedStore` (RSA-4096 +
  AES-256-GCM envelope, same on-disk file format, 10 MB cap, extension
  allow-list, path-traversal sanitizing).
- NIPO follow-up (filed-but-unacknowledged reminder, 24 h interval) → Laravel
  task scheduler; notifications through Laravel's mail layer with a
  local/mock driver (parity with `LocalEmailProvider`).
- The 6 domain invariants in CONTEXT.md (deadline integrity, role separation,
  document immutability, audit trail, confidentiality, docketing as source of
  truth) each get explicit tests.

## 8. UI (L6)

- Blade/Livewire pages mapping 1:1 from React: `/login`, `/admin`
  (dashboard, patents, users), `/user` (overview, patents, notifications),
  vault unlock modal with countdown, DataTable pagination, dark mode via
  existing theme tokens.
- One source of truth for roles (backend enum) — resolves the current
  frontend/API role mismatch (`admin/attorney/paralegal` vs
  `user/director/md`, issues #9/#10).
- React component tests retire; replaced by Pest feature tests + Dusk smoke
  tests.

## 9. Testing & CI

- Port the 310 pytest tests layer by layer into Pest; each layer's PR must be
  green before the next starts. Keep the 80% coverage gate.
- CI workflow gains a `backend-laravel` job (PHP 8.3, `composer install`,
  `pest`); the Python job stays until L7, then is removed.
- TDD per AGENTS.md: red-green-refactor, one test at a time, tests through
  public interfaces, never refactor in red.

## 10. Cutover & rename (L7)

- Delete `app/`, `frontend/`, Python `tests/`, `pyproject.toml`,
  `blitto_patent_management.egg-info/`, `build/`.
- Rename GitHub repo → `blitto-patent-database` (gh CLI) and local folder.
- Rewrite README/CONTEXT/Agent docs for the Laravel stack; remove stale
  Java/Spring references; regenerate graphify (`graphify update .`).
- Rename impact map (from repo audit): ~120 "blitto" occurrences —
  `BLITTO_*` env vars (17), service name, `blitto_token`/`blitto-theme`/
  `blitto:unauthorized` (removed with SPA), display strings,
  `com.blitto.patent.*` docs references, generated artifacts (regenerate,
  never edit).

## 11. Out of scope

- Live-data migration (no real DB exists; dev-data JSON becomes a seeder).
- PostgreSQL/MySQL (SQLite now; schema is portable via migrations).
- better-auth/JWKS production adapter (ADR-0002 — session auth supersedes it).
- Password reset, 2FA, real SMTP/SendGrid wiring (previously listed as
  future work; unchanged).
- Installing PHP/Composer before L0 execution begins.

## 12. Risks

- **Toolchain gap:** PHP/Composer absent locally; L0 starts with install.
- **Test port volume:** 310 backend tests — porting is the bulk of the
  effort; layer gates prevent silent behavior loss.
- **Vault crypto parity:** RSA+AES envelope must stay file-compatible with
  documents encrypted by the Python store during the parallel-run window.
- **Dual-run drift:** while both apps coexist, fixes to shared behavior must
  be applied to the FastAPI oracle and ported, or the oracle loses value.
