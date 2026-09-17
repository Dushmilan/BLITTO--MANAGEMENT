"""SQLite snapshot store — restart-safe persistence (issue #51).

Sits beside the in-memory adapters (which stay the default for tests):
each top-level snapshot collection from ``scripts.persistence.dump_state``
is stored as one JSON row, written atomically in a single transaction.
``BLITTO_STORAGE=sqlite`` selects it at startup (see ``app.main``).
"""

from __future__ import annotations

import json
import sqlite3
from pathlib import Path
from typing import Any

_SCHEMA = """
CREATE TABLE IF NOT EXISTS snapshots (
    collection TEXT PRIMARY KEY,
    data TEXT NOT NULL
)
"""


class SQLiteSnapshotStore:
    def __init__(self, path: str) -> None:
        self._path = Path(path)

    def save(self, state: Any) -> None:
        """Persist every module's state to SQLite (atomic replace)."""
        from scripts.persistence import dump_state

        data = dump_state(state)
        self._path.parent.mkdir(parents=True, exist_ok=True)
        with sqlite3.connect(self._path) as conn:
            conn.execute(_SCHEMA)
            conn.executemany(
                "INSERT OR REPLACE INTO snapshots (collection, data) VALUES (?, ?)",
                [(key, json.dumps(value, default=str)) for key, value in data.items()],
            )
            conn.commit()

    def load(self, state: Any) -> bool:
        """Restore module state from SQLite. False when no snapshot exists yet."""
        from scripts.persistence import restore_state

        if not self._path.exists():
            return False
        with sqlite3.connect(self._path) as conn:
            conn.execute(_SCHEMA)
            rows = conn.execute("SELECT collection, data FROM snapshots").fetchall()
        if not rows:
            return False
        restore_state(state, {key: json.loads(value) for key, value in rows})
        return True
