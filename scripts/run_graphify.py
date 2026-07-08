#!/usr/bin/env python
"""Regenerate the architecture knowledge graph with graphify.

graphify ingests the codebase (tree-sitter, fully local) and emits into
`graphify-out/`:
  - graph.json       machine-readable graph (used for codebase queries)
  - graph.html       interactive visualization
  - GRAPH_REPORT.md  summary of abstractions / architecture issues

We use the LLM-free extraction path (`update`) so the graph stays current with
no API key. Run on every feature add / commit (see .pre-commit-config.yaml and
Makefile). Codebase searching is done via graph.json, not raw grep.
"""

from __future__ import annotations

import shlex
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def _graphify_python() -> str:
    """Find a Python interpreter that has graphify installed.

    The hook may run under a different interpreter than the one graphifyy was
    installed into (e.g. pre-commit uses py3.9 while graphifyy needs >=3.10).
    Prefer `py -3.14`, then any `python3.1x`, else the current executable.
    """
    if shutil.which("py"):
        for spec in ("-3.14", "-3.13", "-3.12", "-3.11", "-3.10"):
            if subprocess.run(
                ["py", spec, "-c", "import graphify"],
                capture_output=True,
            ).returncode == 0:
                return f"py {spec}"
    for cand in ("python3.14", "python3.13", "python3.12", "python3.11", "python3.10"):
        if shutil.which(cand):
            return cand
    return sys.executable


def main() -> int:
    py = _graphify_python()
    base = shlex.split(py)
    try:
        code = subprocess.call([*base, "-m", "graphify", "update", str(ROOT)])
        if code != 0:
            return code
        return subprocess.call(
            [*base, "-m", "graphify", "cluster-only", str(ROOT), "--no-label"]
        )
    except FileNotFoundError:
        print(
            "graphify not found. Install dev dependencies: pip install -e '.[dev]' "
            "(requires Python >=3.10).",
            file=sys.stderr,
        )
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
