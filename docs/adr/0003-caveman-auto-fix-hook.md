# ADR 0003: Caveman auto-fix hook on commit

## Status
Accepted

## Context
We want every commit to pass a code review in **caveman-review** style
(ultra-compressed, one-line findings) and have issues **resolved before the
commit lands**, not just reported. The environment has the `opencode` CLI
available and an existing `pre-commit` (Python framework) config that already
runs graphify.

## Decision
Add a **`caveman-fix`** local hook to `.pre-commit-config.yaml` that gates commits
via opencode in caveman-review style. Two phases:

1. **Gate (pre-commit hook, default):** Extracts the staged diff, sends it to
   `opencode run --auto` in *review-only* mode. If opencode reports any 🔴 bug,
   the commit is **blocked**. 🟡 risk / 🔵 nit / ❓ question findings are
   printed as warnings but do not block. This guarantees no unfixed *critical*
   issue ships while avoiding churn on fragile-but-working code.
2. **Apply (`make caveman-fix` / `scripts/caveman_fix.py --apply`):** Runs
   opencode in *fix* mode — it applies fixes to the working tree (🔴/🟡
   prioritized), then re-stages only the originally-staged files it touched. The
   developer reviews, `git add`s, and commits (which now passes the gate).

opencode is explicitly barred from running `git commit` (no hook recursion).
Scope is limited to the staged diff to avoid unrelated churn.

Behavior is **fail-closed**: if opencode errors or times out, the commit is
blocked. Set `BLITTO_CAVEMAN_ALLOW_SKIP=1` to instead warn and commit
unfixed. Model is configurable via `BLITTO_CAVEMAN_MODEL`; timeout via
`BLITTO_CAVEMAN_TIMEOUT` (default 300s).

## Consequences
- Commits never ship code with unresolved 🔴/🟡 findings (intent met:
  "resolve all issues, then commit").
- In-hook file mutation was rejected: pre-commit's tree builder corrupts when a
  hook rewrites committed files mid-commit, so fixing is an explicit step.
- Requires a working `opencode` provider/model; otherwise commits are blocked
  by design (safety over convenience).
- No infinite recursion because opencode is explicitly barred from committing.
