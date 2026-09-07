"""Dev-data persistence: save/load in-memory module state to/from JSON.

Lets the seed run once, then persists state to ``dev-data/state.json`` so
subsequent startups skip seeding and pick up any changes made during the
previous session.
"""

from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any

from app.domain.common import AuditLog, StatusHistory
from app.modules.application_intake.models import Application
from app.modules.authorization.models import User
from app.modules.filing_workflow.models import FilingRecord
from app.modules.notification.models import Notification
from app.modules.prosecution.models import DefectSheet, OfficeAction, Response


def save_dev_state(state: Any, path: str = "dev-data/state.json") -> None:
    """Serialize every module's in-memory dicts/lists to JSON."""
    data: dict[str, Any] = {"version": 1}

    auth = state["authorization"]
    data["authorization"] = {
        "users": {uid: u.model_dump(mode="json") for uid, u in auth._users.items()},
        "passwords": dict(auth._passwords),
    }

    intake = state["application_intake"]
    data["application_intake"] = {
        "store": {aid: a.model_dump(mode="json") for aid, a in intake._store.items()},
        "history": {
            aid: [h.model_dump(mode="json") for h in hist]
            for aid, hist in intake._history.items()
        },
    }

    pros = state["prosecution"]
    data["prosecution"] = {
        "actions": {oid: a.model_dump(mode="json") for oid, a in pros._actions.items()},
        "responses": {rid: r.model_dump(mode="json") for rid, r in pros._responses.items()},
    }

    notif = state["notification"]
    data["notification"] = {
        "sent": [n.model_dump(mode="json") for n in notif._sent],
    }

    audit = state["audit"]
    data["audit"] = {
        "log": {lid: e.model_dump(mode="json") for lid, e in audit._log.items()},
    }

    filing = state["filing_workflow"]
    data["filing_workflow"] = {
        "records": {aid: r.model_dump(mode="json") for aid, r in filing._records.items()},
        "defect_sheets": {
            did: d.model_dump(mode="json") for did, d in filing._defect_sheets.items()
        },
    }

    out = Path(path)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(data, indent=2, default=str), encoding="utf-8")


def load_dev_state(state: Any, path: str = "dev-data/state.json") -> bool:
    """Deserialize JSON back into module stores.

    Returns True if state was loaded, False if the file doesn't exist.
    """
    src = Path(path)
    if not src.exists():
        return False

    data = json.loads(src.read_text(encoding="utf-8"))

    auth = state["authorization"]
    auth._users = {uid: User.model_validate(u) for uid, u in data["authorization"]["users"].items()}
    auth._passwords = dict(data["authorization"]["passwords"])

    intake = state["application_intake"]
    intake._store = {
        aid: Application.model_validate(a) for aid, a in data["application_intake"]["store"].items()
    }
    intake._history = {
        aid: [StatusHistory.model_validate(h) for h in hist]
        for aid, hist in data["application_intake"]["history"].items()
    }

    pros = state["prosecution"]
    pros._actions = {
        oid: OfficeAction.model_validate(a) for oid, a in data["prosecution"]["actions"].items()
    }
    pros._responses = {
        rid: Response.model_validate(r) for rid, r in data["prosecution"]["responses"].items()
    }

    notif = state["notification"]
    notif._sent = [Notification.model_validate(n) for n in data["notification"]["sent"]]

    audit = state["audit"]
    audit._log = {
        lid: AuditLog.model_validate(e) for lid, e in data["audit"]["log"].items()
    }

    filing = state["filing_workflow"]
    filing._records = {
        aid: FilingRecord.model_validate(r) for aid, r in data["filing_workflow"]["records"].items()
    }
    filing._defect_sheets = {
        did: DefectSheet.model_validate(d) for did, d in data["filing_workflow"]["defect_sheets"].items()
    }

    return True
