# BLITTO PATENT DATABASE — Bigger Picture (context snapshot)

> Date: 2026-10-09 · Status: current north star for all work.
> Supersedes the Approach-A / test-porting sections of
> `docs/superpowers/specs/2026-10-09-blitto-patent-database-laravel-migration-design.md`
> (§3 Approach, §9 test porting, §12 porting/dual-run risks). Everything else in
> that spec (architecture, schema, security, rename map) still applies.
> Tracking issue: [#65](https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/65).

## 1. What we are building

A patent-application management platform for **BLITTO** (Business Linkage
Office, University of Peradeniya) that tracks applications filed with
**NIPO** (National Intellectual Property Office of Sri Lanka).

- Inventors submit patents physically; the system tracks them through the NIPO
  examination pipeline.
- Inventors get read-only access (own applications only) + email notifications
  on status changes; they can download granted patents.
- 3 roles: **MD** (everything incl. user management), **Director** (everything
  except user management), **User** (own applications only).

## 2. Domain core (read `CONTEXT.md` for the full glossary)

- **Lifecycle:** `DRAFT → FILED → {PUBLISHED, ACKNOWLEDGED, EXAMINATION,
  DEFECT_SHEET_1..3, REJECTED} → GRANTED → MAINTENANCE` (illegal transitions
  rejected).
- **6 invariants:** deadline integrity (missed = abandoned), role separation,
  document immutability after filing, docketing is the single source of truth
  for deadlines, audit trail on every state change, confidentiality (users see
  only their own).
- **4 workflows:** intake → prosecution (office actions auto-docket RESPONSE
  deadlines) → grant & maintenance → reporting.
- **Modular monolith:** deep modules (applicationIntake, documentVault,
  prosecution, authorization, notification, portfolioAnalytics, docketing,
  filing_workflow, audit) with seams/adapters — discipline from `Rules.md`
  (its Java/Gradle tooling references are historical; the architecture rules
  still govern).

## 3. Current state (what exists today)

- **Backend:** FastAPI in `app/` — **no database**; in-memory dicts, optional
  SQLite JSON snapshot (`BLITTO_STORAGE=sqlite`), legacy `dev-data/state.json`.
  ~34 endpoints (auth, applications, deadlines/office actions, filing workflow,
  documents/vault, notifications, analytics, users).
- **Frontend:** React (Vite) SPA in `frontend/` — `frontend/src/pages/`
  (login, admin dashboard/patents/users, user panel).
- **Tests:** 310 pytest tests in `tests/` (unit/api/edge/security/journeys
  J1–J6/multi-user e2e). Architecture graph via graphify (`graphify-out/`).
- **Known problems this project fixes:**
  - #51 — restart wipes all state (no real DB)
  - #50 — auth hardening (default JWT secret, throttling)
  - #49 — documents plaintext at rest (Readme claims AES-256)
  - Role-name mismatch: frontend `admin/attorney/paralegal` vs API
    `user/director/md` (#9/#10) — dies with the SPA
  - Two-app deployment (FastAPI + SPA) → single Laravel app for on-prem

## 4. The decision (2026-10-09): complete rewrite, not migration

We build a **new Laravel application from scratch** and delete the Python/React
code at cutover. We do **not** port the pytest suite.

Why not a layer-by-layer migration:

- Porting 310 tests would be the bulk of the effort, and a port inherits the
  old design's quirks and "port-shaped" code.
- Dual-run drift: while both apps live, every shared-behavior fix lands twice.
- The tests encode the design we're leaving, not necessarily the one we want.

What the old code/tests still give us (reference material, **never a gate**):

- Behavioral checklist + edge-case catalog while writing fresh Pest tests
  (throttling numbers, RBAC matrix, journey flows J1–J6, vault rules).
- Field lists: `app/modules/*/models.py` (Pydantic) → basis for the schema.
- Crypto design reference: `PKIEncryptedStore`
  (`app/adapters/document_storage/encrypted/`).
- Seed data: `dev-data/*.json` (plaintext demo passwords get bcrypt'd).

What survives untouched: the domain (`CONTEXT.md`), design tokens
(`DESIGN.md`), the layer sequencing, and the issue tracker.

## 5. Target architecture

- **Stack:** Laravel 13, PHP 8.3+, Blade + Livewire 4, Tailwind (`DESIGN.md`
  tokens), SQLite + Eloquent, Laravel session auth, Pest.
- **Location:** `backend/`; app name **BLITTO PATENT DATABASE**; env prefix
  **`BLITTODB_`** (renamed from `BLITTO_*`).
- **Schema:** 12 tables designed once at L0 — `users`, `applications`,
  `inventors`, `status_history`, `deadlines`, `office_actions`, `responses`,
  `documents`, `filing_records`, `defect_sheets`, `notifications`, `audit_logs`
  — FK-constrained and indexed (replaces the in-memory dict stores).
- **Structure:** controllers stay thin; domain logic in
  `app/Domain/{Lifecycle,Docketing,Filing,Vault,...}/` (deep-module layout per
  `Rules.md`); Eloquent models are persistence-only.
- **Coexistence:** FastAPI stays on `:8000` (reference, untouched), Laravel on
  `:8001`. Python/React deleted only at L7.

## 6. Pipeline (each layer green before the next starts — checklist on #65)

| Layer | Issue | Deliverable | New tests (TDD) |
|---|---|---|---|
| L0 Foundation | [#68](https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/68) | PHP 8.3 + Composer (installed system-wide; CI uses `shivammathur/setup-php`), Laravel 13 skeleton, `BLITTODB_` prefix, SQLite, full 12-table schema, `backend-laravel` CI job | scaffold + health check |
| L1 Domain | [#73](https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/73) | Eloquent models, lifecycle transition table, `app/Domain/*`, 6 invariants | lifecycle/model |
| L2 Auth & RBAC | [#72](https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/72) | Session auth, 3 roles, gates from RBAC matrix, email-domain policy, throttling, bootstrap admin | auth + RBAC + security |
| L3 Core flows | [#67](https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/67) | Applications CRUD, history, status changes, docketing, office actions, filing workflow | feature tests covering journeys J1–J6 |
| L4 Document vault | [#71](https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/71) | Master-key unlock + TTL, encrypted store, upload/download rules, missing-documents worklist | vault + upload security |
| L5 Cross-cutting | [#69](https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/69) | Notifications, audit trail, analytics, NIPO follow-up scheduler, demo seeder | notification/analytics/seed |
| L6 UI | [#70](https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/70) | Blade/Livewire screens replacing all React pages | Pest feature + Dusk smoke |
| L7 Cutover | [#66](https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/66) | Delete `app/`, `frontend/`, Python tests, `pyproject.toml`; rename GitHub repo + folder; rewrite docs | full suite green |

## 7. Rules of engagement

- **TDD always** (`AGENTS.md`): red-green-refactor, one test at a time, tests
  through public interfaces, never refactor while red.
- **graphify:** `graphify query "<question>"` for codebase questions;
  `graphify update .` after modifying code (AST-only, no API cost).
- **Deep modules:** tests at module/public-interface level only; every seam has
  a production + test adapter; no business logic in controllers or Eloquent
  models.
- **Docs:** `CONTEXT.md` first for terminology; ADRs in `docs/adr/` for new
  seams/modules.

## 8. Where to look for what

| Need | Source |
|---|---|
| Domain terms, invariants, workflows | `CONTEXT.md` |
| Requirements/edge cases to test | `tests/` (310 pytest) — reference only |
| Field lists for schema work | `app/modules/*/models.py` |
| Vault crypto design | `app/adapters/document_storage/encrypted/` |
| RBAC matrix | `tests/api/test_rbac_matrix.py` |
| UI behavior + styling | `frontend/src/pages/`, `DESIGN.md` |
| Seed/demo data | `dev-data/*.json` |
| Design decisions, rename map | `docs/superpowers/specs/2026-10-09-blitto-patent-database-laravel-migration-design.md` |

## 9. Current status / next move

- Decision made, issues updated to the rewrite framing (2026-10-09).
- **Done: L0 (#68)** — Laravel 13 skeleton in `backend/`, `BLITTODB_` config
  prefix, SQLite + full 12-table schema, health-check Pest test,
  `backend-laravel` CI job. PHP 8.3.6 + Composer 2.7.1 installed
  system-wide (CI provisions its own via `shivammathur/setup-php`).
- **Done: L1 (#73)** — 12 persistence-only Eloquent models, lifecycle
  transition table, `app/Domain/*`, all 6 CONTEXT.md invariants tested
  (suite 131/131 green).
- **Next: L2 (#72)** — session auth, gates from the RBAC matrix,
  email-domain policy, throttling, bootstrap admin; plus L1 carry-overs:
  upload-flow immutability test, non-empty `reason` validation.
