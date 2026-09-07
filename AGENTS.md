## tdd

All code changes must follow test-driven development (TDD):

- Always load the `tdd` skill before writing code
- Follow the red-green-refactor loop: write a failing test first, write minimal code to pass, then refactor
- Write one test at a time (vertical slices), never batch tests
- Tests must verify behavior through public interfaces, not implementation details
- Never refactor while in RED state

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

When the user types `/graphify`, use the installed graphify skill or instructions before doing anything else.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- Dirty graphify-out/ files are expected after hooks or incremental updates; dirty graph files are not a reason to skip graphify. Only skip graphify if the task is about stale or incorrect graph output, or the user explicitly says not to use it.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).

## caveman-review

After generating caveman-review comments, commit the reviewed changes with a concise message summarizing the findings. Only commit when the user explicitly asks.
