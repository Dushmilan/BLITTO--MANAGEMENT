# ADR 0001: Architecture management with graphify

## Status
Accepted

## Context
The project is a deep-module modular monolith (Agent.md) with strict seam rules
(Rules.md). We need a way to keep module boundaries visible, catch "god nodes"
and unintended couplings, and make the codebase queryable for AI agents. The
backend is FastAPI + Python, no DB, all local.

## Decision
Adopt **graphify** (`graphifyy` on PyPI) as the architecture management tool.
It parses the codebase locally (tree-sitter) and produces `graph.html`,
`GRAPH_REPORT.md`, and `graph.json`. It runs:
- on every commit via a pre-commit hook (`.pre-commit-config.yaml`),
- on demand via `make graph` / `scripts/run_graphify.py`.

Codebase searching is performed via `graph.json` (structural queries) rather
than raw grep, so module/dependency questions stay accurate as the code grows.

## Consequences
- Architecture graph is always current in the repo.
- No code leaves the machine (graphify runs locally).
- Adds `graphifyy` as a dev dependency.
