"""TDD RED: SQLite snapshot persistence (issue #51) — restart-safe state."""

import pytest

from app.adapters.persistence.sqlite_snapshot import SQLiteSnapshotStore
from app.domain.common import Role
from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.application_intake.models import Disclosure
from app.modules.audit.local import LocalAuditModule
from app.modules.authorization.local import LocalAuthorizationModule
from app.modules.authorization.models import LoginRequest, RegisterRequest
from app.modules.docketing.local import LocalDocketingModule
from app.modules.document_vault.local import LocalDocumentVaultModule
from app.modules.filing_workflow.local import LocalFilingWorkflowModule
from app.modules.notification.local import LocalNotificationModule
from app.modules.prosecution.local import LocalProsecutionModule


def _seeded_state():
    auth = LocalAuthorizationModule(secret="sqlite-test-secret-32bytes-min!!")
    intake = LocalApplicationIntakeModule()
    audit = LocalAuditModule()
    vault = LocalDocumentVaultModule()
    docketing = LocalDocketingModule()
    prosecution = LocalProsecutionModule(docketing=docketing, document_vault=vault)
    notification = LocalNotificationModule()
    filing = LocalFilingWorkflowModule(
        application_intake=intake, notification=notification, audit=audit
    )
    auth.register(RegisterRequest(email="keep-md@pdn.ac.lk", role=Role.MD, password="pw"))
    app = intake.submit_disclosure(
        Disclosure(
            title="Keep Me",
            inventor_name="Dr. Keep",
            inventor_email="keep-md@pdn.ac.lk",
            summary="restart-proof",
        )
    )
    doc = vault.store(app.id, "spec.pdf", b"%PDF-keep", "keep-md@pdn.ac.lk")
    audit.record("test:restart", user_id="keep-md@pdn.ac.lk", application_id=app.id)
    return {
        "authorization": auth,
        "application_intake": intake,
        "docketing": docketing,
        "prosecution": prosecution,
        "notification": notification,
        "audit": audit,
        "filing_workflow": filing,
        "document_vault": vault,
    }, app.id, doc.id


def test_sqlite_roundtrip_preserves_users_apps_docs_audit(tmp_path) -> None:
    path = str(tmp_path / "state.sqlite3")
    state, app_id, doc_id = _seeded_state()

    SQLiteSnapshotStore(path).save(state)

    fresh = {
        "authorization": LocalAuthorizationModule(secret="sqlite-test-secret-32bytes-min!!"),
        "application_intake": LocalApplicationIntakeModule(),
        "docketing": LocalDocketingModule(),
        "prosecution": LocalProsecutionModule(
            docketing=LocalDocketingModule(),
            document_vault=LocalDocumentVaultModule(),
        ),
        "notification": LocalNotificationModule(),
        "audit": LocalAuditModule(),
        "filing_workflow": LocalFilingWorkflowModule(
            application_intake=LocalApplicationIntakeModule(),
            notification=LocalNotificationModule(),
            audit=LocalAuditModule(),
        ),
        "document_vault": LocalDocumentVaultModule(),
    }
    assert SQLiteSnapshotStore(path).load(fresh) is True

    auth = fresh["authorization"]
    assert {u.email for u in auth.list_users()} == {"keep-md@pdn.ac.lk"}
    assert auth.login(LoginRequest(email="keep-md@pdn.ac.lk", password="pw")) is not None
    apps = fresh["application_intake"].list_applications()
    assert [a.id for a in apps] == [app_id]
    assert fresh["document_vault"].retrieve(doc_id) == b"%PDF-keep"
    assert len(fresh["audit"].by_action("test:restart")) == 1


def test_sqlite_load_missing_returns_false(tmp_path) -> None:
    fresh = {"authorization": LocalAuthorizationModule(secret="x" * 32)}
    assert SQLiteSnapshotStore(str(tmp_path / "nope.sqlite3")).load(fresh) is False


def test_unknown_storage_backend_rejected(monkeypatch) -> None:
    from app.core.config import settings
    from app.main import resolve_snapshot_store

    monkeypatch.setattr(settings, "storage", "postgres")
    with pytest.raises(ValueError):
        resolve_snapshot_store()


def test_restart_preserves_state_through_lifespan(tmp_path, monkeypatch) -> None:
    from fastapi.testclient import TestClient

    from app.core.config import settings
    from app.main import app

    monkeypatch.setattr(settings, "storage", "sqlite")
    monkeypatch.setattr(settings, "sqlite_path", str(tmp_path / "restart.sqlite3"))

    with TestClient(app):
        app.state.authorization.register(
            RegisterRequest(email="restart-md@pdn.ac.lk", role=Role.MD, password="pw")
        )
    # Second boot = simulated restart; shutdown of the first boot must have saved.
    with TestClient(app) as client:
        r = client.post(
            "/auth/login",
            json={"email": "restart-md@pdn.ac.lk", "password": "pw"},
        )
        assert r.status_code == 200, r.text[:300]
