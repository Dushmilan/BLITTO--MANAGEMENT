"""TDD: persistence must cover docketing + document vault (bytes included)."""

from __future__ import annotations

from datetime import datetime, timedelta, timezone

from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.application_intake.models import Disclosure
from app.modules.docketing.local import LocalDocketingModule
from app.modules.docketing.models import DeadlineType
from app.modules.document_vault.local import LocalDocumentVaultModule
from app.adapters.document_storage.local import LocalDocumentStore
from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
from scripts.persistence import save_dev_state, load_dev_state

from tests.test_persistence import _fresh_modules as _base_fresh  # noqa: F401  (reuse pattern)


def _modules_with_docs():
    vault = LocalDocumentVaultModule(store=LocalDocumentStore())
    intake = LocalApplicationIntakeModule()
    docketing = LocalDocketingModule()
    from app.modules.audit.local import LocalAuditModule
    from app.modules.authorization.local import LocalAuthorizationModule
    from app.modules.filing_workflow.local import LocalFilingWorkflowModule
    from app.modules.notification.local import LocalNotificationModule
    from app.modules.prosecution.local import LocalProsecutionModule
    prosecution = LocalProsecutionModule(docketing=docketing, document_vault=vault)
    notification = LocalNotificationModule()
    audit = LocalAuditModule()
    filing = LocalFilingWorkflowModule(
        application_intake=intake, notification=notification, audit=audit
    )
    return {
        "authorization": LocalAuthorizationModule(),
        "application_intake": intake,
        "docketing": docketing,
        "document_vault": vault,
        "prosecution": prosecution,
        "notification": notification,
        "audit": audit,
        "filing_workflow": filing,
    }


def test_persistence_roundtrips_docketing_and_documents(tmp_path) -> None:
    modules = _modules_with_docs()
    app = modules["application_intake"].submit_disclosure(
        Disclosure(
            title="Persist Doc",
            inventor_name="Dr Persist",
            inventor_email="persist-user@pdn.ac.lk",
            summary="s",
        )
    )
    due = datetime.now(timezone.utc) + timedelta(days=30)
    deadline = modules["docketing"].add_deadline(app.id, DeadlineType.RESPONSE, due)
    doc = modules["document_vault"].store(app.id, "spec.pdf", b"%PDF-1.4 hello", "md@pdn.ac.lk")

    path = tmp_path / "state.json"
    save_dev_state(modules, str(path))

    fresh = _modules_with_docs()
    assert load_dev_state(fresh, str(path)) is True

    deadlines = fresh["docketing"].list_deadlines(app.id)
    assert len(deadlines) == 1
    assert deadlines[0].id == deadline.id
    assert deadlines[0].type == DeadlineType.RESPONSE

    docs = fresh["document_vault"].list_for_application(app.id)
    assert len(docs) == 1
    assert docs[0].id == doc.id
    assert docs[0].filename == "spec.pdf"
    assert fresh["document_vault"].retrieve(doc.id) == b"%PDF-1.4 hello"


def _modules_with_pki(tmp_path):
    store = PKIEncryptedStore(
        cert_dir=str(tmp_path / "certs"),
        storage_dir=str(tmp_path / "docs"),
    )
    store.unlock(store.generated_master_key)
    vault = LocalDocumentVaultModule(store=store)
    intake = LocalApplicationIntakeModule()
    docketing = LocalDocketingModule()
    from app.modules.audit.local import LocalAuditModule
    from app.modules.authorization.local import LocalAuthorizationModule
    from app.modules.filing_workflow.local import LocalFilingWorkflowModule
    from app.modules.notification.local import LocalNotificationModule
    from app.modules.prosecution.local import LocalProsecutionModule
    prosecution = LocalProsecutionModule(docketing=docketing, document_vault=vault)
    notification = LocalNotificationModule()
    audit = LocalAuditModule()
    filing = LocalFilingWorkflowModule(
        application_intake=intake, notification=notification, audit=audit
    )
    return {
        "authorization": LocalAuthorizationModule(),
        "application_intake": intake,
        "docketing": docketing,
        "document_vault": vault,
        "prosecution": prosecution,
        "notification": notification,
        "audit": audit,
        "filing_workflow": filing,
    }


def test_persistence_roundtrips_pki_encrypted_blobs(tmp_path, monkeypatch) -> None:
    monkeypatch.chdir(tmp_path)
    modules = _modules_with_pki(tmp_path / "orig")
    app = modules["application_intake"].submit_disclosure(
        Disclosure(
            title="PKI Doc",
            inventor_name="Dr PKI",
            inventor_email="persist-user@pdn.ac.lk",
            summary="s",
        )
    )
    doc = modules["document_vault"].store(app.id, "secret.pdf", b"top-secret-bytes", "md@pdn.ac.lk")
    master = modules["document_vault"]._store.generated_master_key

    path = tmp_path / "state.json"
    save_dev_state(modules, str(path))

    fresh = _modules_with_pki(tmp_path / "restored")
    assert load_dev_state(fresh, str(path)) is True
    assert fresh["document_vault"].unlock(master) is True
    assert fresh["document_vault"].retrieve(doc.id) == b"top-secret-bytes"
