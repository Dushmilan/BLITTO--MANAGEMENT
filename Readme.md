# BLITTO Patent Management System

> **Status (2026):** Reimplemented as a **FastAPI + Python** backend — **no database**, all state in-memory/local, for local development and prototyping. Architecture is managed with **graphify** (see *Architecture management* below). The original Java/Spring design intent is preserved in `Agent.md` and `Rules.md`.

A secure, role-based platform for the **Business Linkage Office (BLITTO)** at the University of Peradeniya to manage patent applications filed with the **National Intellectual Property Office of Sri Lanka (NIPO)**.

---

## Overview

BLITTO serves as the bridge between university inventors and NIPO. Inventors submit patents physically; the system tracks applications through the NIPO examination pipeline. Inventors receive limited, read-only access via user codes to monitor their patent status and receive email notifications on status changes.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Frontend | React (Vite) in `frontend/` — dev proxy `/api` → `localhost:8000` |
| Backend | FastAPI (Python) |
| Database | None (in-memory stores; optional JSON snapshot in `dev-data/state.json`) |
| Authentication | Local stub (institution email + password, Bearer tokens) — better-auth JWT/JWKS adapter reserved for prod |
| Document Storage | Local in-memory/file adapter (default); PKI-encrypted adapter exists but is not the default |
| Encryption | `PKIEncryptedStore` adapter (RSA/AES hybrid) exists; **default store is plaintext at rest** (see issue #49) |
| Architecture mgmt | graphify (`graphifyy`) |

---

## User Roles (3 types)

### MD
- Full system access (owns user management: creates Director/MD accounts)
- Create and manage patent records
- Generate and assign User Codes to users
- Upload, encrypt, and manage PDF documents
- Change patent application status
- View audit logs and the missing-documents worklist
- Search all patent metadata

### Director
- Same use case as MD, except user management (cannot create Director/MD accounts)
- Create and manage patent records
- Upload and manage PDF documents, docket deadlines, handle office actions
- Change patent application status
- View the missing-documents worklist

### User
- Register using a User Code provided by BLITTO
- Log in with email and password
- View only their own patents (reference code + status)
- Receive email notifications on status changes
- Download own GRANTED patent documents (timestamped, audited); if granted but
  no document is uploaded yet, request it from BLITTO via the portal
- Register / log in with institution mail only (`pdn.ac.lk`)
- **No uploads, no metadata beyond status**

---

## Authentication Flow

1. **Physical Submission**: Inventor submits patent application physically to BLITTO
2. **Record Creation**: Director/MD creates patent record in the system
3. **User Code Generation**: Director/MD generates a unique User Code (e.g., `INV-2026-0042`) and provides it to the user
4. **Registration**: User registers on the platform using:
   - User Code (provided by BLITTO)
   - Institution email address
   - Self-chosen password
5. **Access**: User logs in to view patent status and receive notifications

---

## Patent Application Lifecycle

```
[Draft] → [Submitted to NIPO] → [Under Examination] → [Office Action]
    → [Response Filed] → [Allowed] → [Granted]
                      ↘ [Rejected]
```

> **Status transitions are Director/MD-only.** Users receive email notifications on any status change.

---

## Document Security (current state)
- **Store selectable via `BLITTO_DOCUMENT_STORE`**: `local` (plaintext, dev-only — startup fails if `environment != local`) or `pki` (RSA-4096 + AES-256-GCM envelope). Production must set `pki`.
- **Master key never persisted by the app**: first run emits the base64 key once to the log; store it in your secret manager as `BLITTO_VAULT_MASTER_KEY`, then call `POST /api/vault/unlock`.
- **Vault gate**: all document endpoints still require an unlocked vault (423 when locked). The PKI store additionally supports encrypt-with-public-key, so uploads can be decoupled from unlock later; the API does not expose that yet (see `test_vault_blocks_upload_when_locked`).
- **Uploads**: 10 MB cap, extension allow-list, 423 when locked, 429 after 5 failed unlocks per IP / 10 min.

---

## Data Model (in-memory)

| Entity | Key Fields |
|--------|-----------|
| **Users** | `id`, `email` (institution mail only), `role` (user/director/md), `user_code`, `created_at` |
| **Applications** | `id`, `title`, `user_code`, `status`, `inventor_name`, `inventor_email`, `created_at`, `updated_at` |
| **Documents** | `id`, `application_id`, `filename`, `content_type`, `uploaded_by`, `uploaded_at` |
| **Deadlines** | `id`, `application_id`, `type` (FILING/RESPONSE/MAINTENANCE_FEE), `status` (OPEN/MET/…), `due_date` |
| **OfficeActions** | `id`, `application_id`, `kind`, `body` (+ responses) |
| **Filing** | NIPO ack refs, defect sheets (max 3, numbered 1–3), file/grant/reject records |
| **AuditLogs** | actor, action (`download_granted_patent`, `grant_without_document`, …), timestamp |

---

## API & Backend Notes

- **Modular FastAPI monolith** — one deployable; domain modules under
  `app/modules/<name>/` (`interface.py` Protocol + `local/` in-memory adapter):
  `application_intake`, `authorization`, `docketing`, `document_vault`,
  `prosecution`, `filing_workflow`, `portfolio_analytics`, `notification`, `audit`
- **Routes are dual-mounted** at `/...` and `/api/...` (`app/main.py`); the
  frontend dev proxy targets `/api`
- **Authentication**: `POST /auth/register` (self-register as `user`; only MD
  may create director/MD roles), `POST /auth/login` (public, institution mail),
  `POST /auth/token` (MD-only), `GET /auth/me`, `GET /users` (MD-only)
- **Authorization**: role gates on every endpoint (`MDUser`, `StaffUser`
  = director+md, `CurrentUser`); users see only their own applications
- **Key flows**: GRANTED download (`GET /applications/{id}/download`, 409
  `document_pending` when no file yet), `.../request-document` (rate-limited),
  `GET /applications/missing-documents`, docketing (`POST
  /applications/{id}/deadlines`, `GET /deadlines`), office actions
  (auto-docket response deadlines), filing workflow (acknowledge / defect
  sheets / file / grant / reject), analytics (`/analytics/portfolio`,
  `/analytics/deadlines`)
- **Tests**: `make test` (pytest), `make test-cov` (coverage gate: fail under
  80%), `make test-e2e` (Playwright journeys vs live server, no browsers needed)

---

## Deployment

- **Target**: University-owned server (on-premise), single `uvicorn` process
- **Scale target**: 1,000 users
- **Database**: none — in-memory stores; optional JSON snapshot persistence via
  `scripts/persistence.py` (`dev-data/state.json`) for local dev only

---

## Out of Scope

- Multi-jurisdictional support (NIPO/Sri Lanka only)
- Full-text PDF search
- AI/automated features
- Integration with NIPO APIs (manual status updates)
- Document versioning
- Inventor document upload capability
- Public status check page without login

---

## Future Considerations

- Document versioning
- NIPO API integration if available
- Two-factor authentication (2FA)
- Self-service password recovery workflows

---

## Architecture Management (graphify)

The codebase is a deep-module modular monolith (see `Agent.md`, `Rules.md`). We
use **graphify** to keep module boundaries visible and to make the code
queryable for AI agents.

- Install dev deps (graphify needs Python >=3.10): `pip install -e ".[dev]"`
- Regenerate graph: `make graph` (or `python scripts/run_graphify.py`) — uses the
  LLM-free `graphify update` path, so no API key is required
- Runs automatically on every commit via the pre-commit hook
- Artifacts (in `graphify-out/`): `graph.json` (machine-readable, used for
  structural codebase queries), `graph.html` (visual), `GRAPH_REPORT.md` (summary)
- **Codebase searching is done through `graphify-out/graph.json`**, not raw grep
  (e.g. `graphify path "A" "B"`, `graphify explain "X"`)
- Wire graphify into your agent: `py -3.14 -m graphify opencode install`

### Caveman review on commit

Every `git commit` runs a **caveman-review gate** (see `docs/adr/0003`):
- The staged diff is sent to `opencode run ... --auto` in caveman style.
- **Review-only gate**: opencode reports findings; the commit is **blocked** if
  any 🔴 bug / 🟡 risk is found, so unfixed issues never ship. Nit/question
  findings do not block.
- To actually fix: run `make caveman-fix` (`python scripts/caveman_fix.py
  --apply`), which has opencode apply the fixes to the working tree, then
  `git add` + commit (which now passes the gate).
- **Fail-closed**: if opencode is unavailable/errors, the commit is blocked.
  Override with `BLITTO_CAVEMAN_ALLOW_SKIP=1`.
- Config: `BLITTO_CAVEMAN_MODEL` (model), `BLITTO_CAVEMAN_TIMEOUT` (default 300s).
- Implemented as the `caveman-fix` hook in `.pre-commit-config.yaml`
  (`scripts/caveman_fix.py`), alongside the `graphify` hook.

Enable both hooks: `pre-commit install` (requires `pip install -e ".[dev]"`).

### Module layout (Python)

```
app/modules/<name>/interface.py   # XxxModule Protocol (module boundary)
app/modules/<name>/local/         # in-memory adapter (default)
app/modules/authorization/better_auth/  # better-auth JWT/JWKS adapter (prod)
app/adapters/document_storage/    # document storage seam (ports-and-adapters)
app/adapters/email/               # email provider seam (true-external)
```

### Run

```bash
pip install -e ".[dev]"
uvicorn app.main:app --reload --port 8000
# docs at http://localhost:8000/docs
```

### Run the frontend

```bash
cd frontend && npm install && npm run dev
# vite dev server proxies /api -> http://localhost:8000
```

> **Note:** the frontend still uses the pre-merge role model
> (`admin/attorney/paralegal/inventor`) and does not match the current
> `user/director/md` API — see issues #9/#10.
