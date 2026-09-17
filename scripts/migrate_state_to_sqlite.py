"""Migrate an existing JSON snapshot (dev-data/state.json) into SQLite.

Usage:
    python scripts/migrate_state_to_sqlite.py [json_path] [sqlite_path]

Defaults mirror the app config: dev-data/state.json -> dev-data/state.sqlite3.
After migrating, set BLITTO_STORAGE=sqlite and restart the app.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from app.adapters.persistence.sqlite_snapshot import SQLiteSnapshotStore  # noqa: E402
from app.modules.application_intake.local import LocalApplicationIntakeModule  # noqa: E402
from app.modules.audit.local import LocalAuditModule  # noqa: E402
from app.modules.authorization.local import LocalAuthorizationModule  # noqa: E402
from app.modules.docketing.local import LocalDocketingModule  # noqa: E402
from app.modules.document_vault.local import LocalDocumentVaultModule  # noqa: E402
from app.modules.filing_workflow.local import LocalFilingWorkflowModule  # noqa: E402
from app.modules.notification.local import LocalNotificationModule  # noqa: E402
from app.modules.prosecution.local import LocalProsecutionModule  # noqa: E402
from scripts.persistence import restore_state  # noqa: E402


def main(json_path: str, sqlite_path: str) -> None:
    data = json.loads(Path(json_path).read_text(encoding="utf-8"))
    doc_vault = LocalDocumentVaultModule()
    intake = LocalApplicationIntakeModule()
    notification = LocalNotificationModule()
    audit = LocalAuditModule()
    state = {
        "authorization": LocalAuthorizationModule(),
        "application_intake": intake,
        "docketing": LocalDocketingModule(),
        "prosecution": LocalProsecutionModule(
            docketing=LocalDocketingModule(), document_vault=doc_vault
        ),
        "notification": notification,
        "audit": audit,
        "filing_workflow": LocalFilingWorkflowModule(
            application_intake=intake,
            notification=notification,
            audit=audit,
        ),
        "document_vault": doc_vault,
    }
    restore_state(state, data)
    SQLiteSnapshotStore(sqlite_path).save(state)
    print(f"migrated {json_path} -> {sqlite_path}")


if __name__ == "__main__":
    json_src = sys.argv[1] if len(sys.argv) > 1 else "dev-data/state.json"
    sqlite_dst = sys.argv[2] if len(sys.argv) > 2 else "dev-data/state.sqlite3"
    main(json_src, sqlite_dst)
