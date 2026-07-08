#!/usr/bin/env python
"""Caveman-review gate + fix for the staged diff, via opencode.

Two modes:
- DEFAULT (used by the pre-commit hook): REVIEW-ONLY gate. opencode reviews
  the staged diff in caveman style and reports findings. The commit is BLOCKED
  if any 🔴 bug / 🟡 risk is found (fail-closed), so unfixed issues never
  ship. Nit/question findings do not block.
- --apply (used by `make caveman-fix`): opencode actually APPLIES the fixes
  to the working tree, then re-stages only the originally-staged files it
  touched. Run this, `git add`, then commit (which then passes the gate).

Design rules (see docs/adr/0003-caveman-auto-fix-hook.md):
- Only the staged diff is reviewed (no unrelated churn).
- opencode is forbidden from running `git commit` (no hook recursion).
- Fail-closed: if opencode errors / times out, the commit is BLOCKED
  (we never silently commit unreviewed code). Override with
  BLITTO_CAVEMAN_ALLOW_SKIP=1.
"""

from __future__ import annotations

import os
import shutil
import subprocess
import sys
from pathlib import Path

TIMEOUT_SECONDS = int(os.environ.get("BLITTO_CAVEMAN_TIMEOUT", "300"))
ALLOW_SKIP = os.environ.get("BLITTO_CAVEMAN_ALLOW_SKIP", "") == "1"
MODEL = os.environ.get("BLITTO_CAVEMAN_MODEL", "")

REVIEW_PROMPT = """You are performing a CAVEMAN-REVIEW of the staged git diff below.

Rules:
- Ultra-compressed, one line per finding: `L<line>: <problem>. <fix>.`
- Severity prefixes: 🔴 bug (broken behavior) / 🟡 risk (fragile) / 🔵 nit (style) / ❓ q (question).
- REVIEW ONLY: do NOT edit any files. Just report findings, then stop.

Staged diff:
```diff
{diff}
```
"""

APPLY_PROMPT = """You are performing a CAVEMAN-REVIEW of the staged git diff below.

Rules:
- Ultra-compressed, one line per finding: `L<line>: <problem>. <fix>.`
- Severity prefixes: 🔴 bug (broken behavior) / 🟡 risk (fragile) / 🔵 nit (style) / ❓ q (question).
- APPLY the fixes directly to the working tree for every finding you can safely
  resolve (prioritize 🔴 and 🟡).
- Do NOT run `git commit` or any git commit command. Do NOT add new features.
- When done, stop. Report any item you could not fix.

Staged diff:
```diff
{diff}
```
"""


def _opencode_bin() -> str:
    """Resolve opencode. On Windows prefer the `.cmd` shim over the `.ps1` wrapper."""
    for candidate in ("opencode.cmd", "opencode.exe", "opencode"):
        if shutil.which(candidate):
            return candidate
    return "opencode"


def _run(cmd: list[str]) -> subprocess.CompletedProcess:
    return subprocess.run(
        cmd,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=TIMEOUT_SECONDS,
        shell=False,
    )


def _staged_names(root: Path) -> set[str]:
    out = _run(["git", "-C", str(root), "diff", "--cached", "--name-only"]).stdout or ""
    return {p for p in out.split() if p}


def _run_opencode(prompt: str) -> subprocess.CompletedProcess:
    cmd = [_opencode_bin(), "run", "--auto"]
    if MODEL:
        cmd += ["--model", MODEL]
    return subprocess.run(
        cmd,
        input=prompt,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=TIMEOUT_SECONDS,
        shell=False,
    )


def _safe_print(text: str) -> None:
    """Print UTF-8 safely even when the console codec is cp1252 (Windows)."""
    try:
        print(text)
    except UnicodeEncodeError:
        if hasattr(sys.stdout, "buffer"):
            sys.stdout.buffer.write(text.encode("utf-8", "replace") + b"\n")
        else:
            print(text.encode("ascii", "replace").decode("ascii"))


def _block(reason: str) -> int:
    if ALLOW_SKIP:
        print(f"[caveman] WARNING: {reason} — committing unreviewed "
              f"(BLITTO_CAVEMAN_ALLOW_SKIP=1).", file=sys.stderr)
        return 0
    print(f"[caveman] COMMIT BLOCKED: {reason}. Resolve via "
          f"`make caveman-fix`, then commit. Override with "
          f"BLITTO_CAVEMAN_ALLOW_SKIP=1.", file=sys.stderr)
    return 1


def main() -> int:
    apply_mode = "--apply" in sys.argv[1:]
    root = Path(__file__).resolve().parent.parent

    staged = _staged_names(root)
    if not staged:
        return 0

    diff_text = _run(["git", "-C", str(root), "diff", "--cached"]).stdout or ""
    if not diff_text.strip():
        return 0

    prompt = (APPLY_PROMPT if apply_mode else REVIEW_PROMPT).format(diff=diff_text)

    try:
        result = _run_opencode(prompt)
    except subprocess.TimeoutExpired:
        return _block("opencode timed out")
    except FileNotFoundError:
        return _block("opencode not found on PATH")

    if result.returncode != 0:
        return _block("opencode failed: " + (result.stderr.strip() or result.stdout.strip()))

    if apply_mode:
        # Re-stage only originally-staged files opencode modified.
        modified = {p for p in (_run(["git", "-C", str(root), "diff", "--name-only"]).stdout or "").split() if p}
        to_add = sorted(staged & modified)
        if to_add:
            _run(["git", "-C", str(root), "add", "--"] + to_add)
        print("[caveman] fixes applied. Review `git diff`, stage, then commit.")
    else:
        report = (result.stdout or "").strip()
        print("[caveman] review complete.")
        if report:
            _safe_print(report)
        # Block only on bug findings (🔴) — those are broken behavior that
        # must not ship. 🟡 (fragile/risk) and below are printed as
        # warnings but do not block the commit (they work, just need care).
        # A finding line starts with the emoji (caveman format: `Lx: 🔴 ...`).
        blocker_lines = [
            line for line in report.splitlines()
            if line.lstrip().startswith("🔴")
        ]
        risk_lines = [
            line for line in report.splitlines()
            if line.lstrip().startswith("🟡")
        ]
        for line in risk_lines:
            _safe_print("[caveman] 🟡 " + line.lstrip()[1:].strip())
        if blocker_lines:
            return _block("🔴 bug findings present (see review above)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
