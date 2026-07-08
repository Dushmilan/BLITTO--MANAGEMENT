# ADR 0002: Authorization via better-auth

## Status
Accepted

## Context
The original Readme specified Supabase Auth. The reimplementation is FastAPI +
Python with no DB and all-local operation. We chose **better-auth**
(https://github.com/better-auth/better-auth) as the auth provider.

better-auth is a TypeScript, framework-agnostic auth library with **no native
Python SDK**. It must run as a separate auth server (e.g. Node/Next.js) that
issues JWTs.

## Decision
- `authorization` is a local-substitutable seam (Agent.md).
- Production adapter (`app/modules/authorization/better_auth`) verifies
  better-auth JWTs via its JWKS endpoint (resource-server pattern) using PyJWT.
- Local stub adapter (`app/modules/authorization/local`) issues and verifies its
  own dev JWT so the skeleton runs with zero external services.
- Token issuance and user registration are owned by the better-auth server;
  the backend only verifies tokens and reads claims (sub, email, role,
  user_code).

## Consequences
- Skeleton runs locally today; swapping to production is a one-line change in
  `app/main.py` (select `BetterAuthAuthorizationModule` + set JWKS env vars).
- No user passwords are handled by this backend.
