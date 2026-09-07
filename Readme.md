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
| Frontend | React.js (future) |
| Backend | FastAPI (Python) |
| Database | None (in-memory / local adapters) |
| Authentication | better-auth (JWT/JWKS) — local stub in skeleton |
| Document Storage | Local in-memory/file adapter (S3 seam reserved) |
| Encryption | Server-side PKI (RSA/AES hybrid) — future |
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

## Document Security

- **Encryption**: Server-side PKI using RSA/AES hybrid encryption
- **Key Management**: Admin-managed certificates; master key stored offline in a physical locker
- **Storage**: Encrypted PDFs only, stored in Google Drive
- **Decryption**: On-demand, server-side, logged in audit trail
- **No client-side encryption**: PDFs are uploaded in plaintext by Admin; encryption happens before Google Drive storage

---

## Data Model

| Entity | Key Fields |
|--------|-----------|
| **Users** | `id`, `email`, `role` (user/director/md), `user_code`, `supabase_uid`, `created_at` |
| **Patents** | `id`, `title`, `application_number`, `user_code`, `status`, `nipo_reference`, `inventor_name`, `inventor_email`, `created_at`, `updated_at` |
| **Documents** | `id`, `patent_id`, `filename`, `google_drive_file_id`, `encryption_key_id`, `uploaded_by`, `uploaded_at` |
| **StatusHistory** | `id`, `patent_id`, `old_status`, `new_status`, `changed_by`, `changed_at` |
| **AuditLogs** | `id`, `user_id`, `patent_id`, `action`, `timestamp`, `ip_address` |

---

## API & Backend Notes

- **Monolith architecture** — single Spring Boot deployable
- **Authentication**: JWT tokens via Supabase Auth
- **Authorization**: Role checks on every endpoint; users filtered by `user_code`
- **File handling**: Stream to memory → encrypt → upload to Google Drive; reverse for download

---

## Deployment

- **Target**: University-owned server (on-premise)
- **Scale target**: 1,000 users
- **Database**: PostgreSQL on same server or managed instance

---

## Out of Scope

- Multi-jurisdictional support (NIPO/Sri Lanka only)
- Full-text PDF search
- AI/automated features
- Integration with NIPO APIs (manual status updates)
- Document versioning
- Statutory deadline reminders
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
