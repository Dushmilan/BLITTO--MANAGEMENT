"""Dev-data persistence: save/load in-memory module state to/from JSON.

Lets the seed run once, then persists state to ``dev-data/state.json`` so
subsequent startups skip seeding and pick up any changes made during the
previous session.
"""

from __future__ import annotations

import base64
import json
import os
from pathlib import Path
from typing import Any

from app.domain.common import AuditLog, StatusHistory
from app.modules.application_intake.models import Application
from app.modules.authorization.models import User
from app.modules.docketing.models import Deadline
from app.modules.document_vault.models import Document
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

    if "docketing" in state:
        docketing = state["docketing"]
        data["docketing"] = {
            "deadlines": {
                did: d.model_dump(mode="json") for did, d in docketing._store.items()
            },
        }

    if "document_vault" in state:
        vault = state["document_vault"]
        data["document_vault"] = {
            "documents": {
                did: d.model_dump(mode="json") for did, d in vault._index.items()
            },
            "contents": {
                ref: base64.b64encode(content).decode()
                for ref, content in getattr(vault._store, "_data", {}).items()
            },
        }
        store = vault._store
        if hasattr(store, "_cert_dir") and hasattr(store, "_storage_dir"):
            cert_dir = Path(store._cert_dir)
            storage_dir = Path(store._storage_dir)
            blobs: dict[str, str] = {}
            if storage_dir.exists():
                for blob_path in sorted(storage_dir.glob("*.enc")):
                    blobs[blob_path.name] = base64.b64encode(blob_path.read_bytes()).decode()
            pki: dict[str, Any] = {"blobs": blobs}
            enc_path = cert_dir / "private_key.enc"
            pub_path = cert_dir / "public_key.pem"
            if enc_path.exists():
                pki["private_key_enc"] = base64.b64encode(enc_path.read_bytes()).decode()
            if pub_path.exists():
                pki["public_key_pem"] = pub_path.read_text(encoding="utf-8")
            data["document_vault"]["pki"] = pki

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
    filing_data = data.get("filing_workflow", {})
    filing._records = {
        aid: FilingRecord.model_validate(r) for aid, r in filing_data.get("records", {}).items()
    }
    filing._defect_sheets = {
        did: DefectSheet.model_validate(d) for did, d in filing_data.get("defect_sheets", {}).items()
    }

    if "docketing" in state and "docketing" in data:
        docketing = state["docketing"]
        docketing._store = {
            did: Deadline.model_validate(d)
            for did, d in data["docketing"].get("deadlines", {}).items()
        }

    if "document_vault" in state and "document_vault" in data:
        vault = state["document_vault"]
        vault._index = {
            did: Document.model_validate(d)
            for did, d in data["document_vault"].get("documents", {}).items()
        }
        store_data = getattr(vault._store, "_data", None)
        if isinstance(store_data, dict):
            store_data.clear()
            for ref, b64 in data["document_vault"].get("contents", {}).items():
                store_data[ref] = base64.b64decode(b64)
        pki = data["document_vault"].get("pki")
        store = vault._store
        if pki and hasattr(store, "_cert_dir") and hasattr(store, "_storage_dir"):
            cert_dir = Path(store._cert_dir)
            storage_dir = Path(store._storage_dir)
            cert_dir.mkdir(parents=True, exist_ok=True)
            storage_dir.mkdir(parents=True, exist_ok=True)
            if pki.get("private_key_enc"):
                (cert_dir / "private_key.enc").write_bytes(
                    base64.b64decode(pki["private_key_enc"])
                )
            if pki.get("public_key_pem"):
                (cert_dir / "public_key.pem").write_text(
                    pki["public_key_pem"], encoding="utf-8"
                )
            for name, b64 in pki.get("blobs", {}).items():
                (storage_dir / name).write_bytes(base64.b64decode(b64))

    return True
