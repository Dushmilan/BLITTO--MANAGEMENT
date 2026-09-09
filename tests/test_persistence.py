"""Tests for dev-data persistence (scripts/persistence.py)."""

from __future__ import annotations

import json
from pathlib import Path
from unittest.mock import patch

import pytest

from app.domain.common import AuditLog, ApplicationStatus, StatusHistory
from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.application_intake.models import Application, Disclosure, Inventor
from app.modules.audit.local import LocalAuditModule
from app.modules.authorization.local import LocalAuthorizationModule
from app.modules.authorization.models import RegisterRequest
from app.modules.document_vault.local import LocalDocumentVaultModule
from app.modules.filing_workflow.local import LocalFilingWorkflowModule
from app.modules.filing_workflow.models import FilingRecord
from app.modules.notification.local import LocalNotificationModule
from app.modules.notification.models import Notification
from app.modules.docketing.local import LocalDocketingModule
from app.modules.prosecution.local import LocalProsecutionModule
from app.modules.prosecution.models import OfficeAction, OfficeActionKind, Response
from scripts.persistence import save_dev_state, load_dev_state


@pytest.fixture
def modules():
    doc_vault = LocalDocumentVaultModule()
    auth = LocalAuthorizationModule()
    intake = LocalApplicationIntakeModule()
    docketing = LocalDocketingModule()
    prosecution = LocalProsecutionModule(docketing=docketing, document_vault=doc_vault)
    notification = LocalNotificationModule()
    audit = LocalAuditModule()
    filing = LocalFilingWorkflowModule(
        application_intake=intake,
        notification=notification,
        audit=audit,
    )
    return {
        "authorization": auth,
        "application_intake": intake,
        "docketing": docketing,
        "prosecution": prosecution,
        "notification": notification,
        "audit": audit,
        "filing_workflow": filing,
    }


@pytest.fixture
def seeded_modules(modules):
    """Populate modules with some sample data."""
    auth = modules["authorization"]
    intake = modules["application_intake"]
    prosecution = modules["prosecution"]
    notification = modules["notification"]
    audit = modules["audit"]
    filing = modules["filing_workflow"]

    # Authorization: two users
    auth.register(RegisterRequest(email="persist-md@pdn.ac.lk", password="pw"))
    auth.register(RegisterRequest(email="persist-user@pdn.ac.lk", password="pw", role="user"))

    # Application intake: one application with status change
    app = intake.submit_disclosure(Disclosure(
        title="Test Patent",
        inventor_name="Dr. Test",
        inventor_email="persist-user@pdn.ac.lk",
        summary="A test patent",
    ))
    intake.change_status(app.id, ApplicationStatus.FILED, changed_by="persist-md@pdn.ac.lk")

    # Prosecution: an office action
    action = prosecution.receive_office_action(
        app.id, OfficeActionKind.OBJECTION, "Claims lack inventive step."
    )
    prosecution.file_response(app.id, action.id, "Response submitted.")

    # Notification: one sent notification
    notification.send_notification("persist-user@pdn.ac.lk", "Welcome", "Welcome to the system.")

    # Audit: one log entry
    audit.record("test:action", user_id="persist-md@pdn.ac.lk", application_id=app.id)

    # Filing workflow: a filing record
    filing._records[app.id] = FilingRecord(
        id="rec-1",
        application_id=app.id,
        filed_by="persist-md@pdn.ac.lk",
    )

    return modules


def _fresh_modules():
    """Create a second set of empty modules to load data into."""
    doc_vault = LocalDocumentVaultModule()
    return {
        "authorization": LocalAuthorizationModule(),
        "application_intake": LocalApplicationIntakeModule(),
        "prosecution": LocalProsecutionModule(docketing=LocalDocketingModule(), document_vault=doc_vault),
        "notification": LocalNotificationModule(),
        "audit": LocalAuditModule(),
        "filing_workflow": LocalFilingWorkflowModule(
            application_intake=LocalApplicationIntakeModule(),
            notification=LocalNotificationModule(),
            audit=LocalAuditModule(),
        ),
    }


class TestRoundTrip:
    def test_save_then_load_restores_all_state(self, seeded_modules, tmp_path):
        state_path = tmp_path / "state.json"

        save_dev_state(seeded_modules, str(state_path))
        assert state_path.exists()

        fresh = _fresh_modules()
        loaded = load_dev_state(fresh, str(state_path))
        assert loaded is True

        # Authorization
        auth_users = fresh["authorization"]._users
        assert len(auth_users) == 2
        admin_user = next(u for u in auth_users.values() if u.email == "persist-md@pdn.ac.lk")
        assert fresh["authorization"]._passwords[admin_user.id] == "pw"

        # Application intake
        apps = list(fresh["application_intake"]._store.values())
        assert len(apps) == 1
        assert apps[0].title == "Test Patent"
        assert apps[0].status == ApplicationStatus.FILED
        history = fresh["application_intake"]._history.get(apps[0].id, [])
        assert len(history) == 1
        assert history[0].new_status == ApplicationStatus.FILED

        # Prosecution
        assert len(fresh["prosecution"]._actions) == 1
        assert len(fresh["prosecution"]._responses) == 1

        # Notification
        assert len(fresh["notification"]._sent) == 1
        assert fresh["notification"]._sent[0].subject == "Welcome"

        # Audit
        assert len(fresh["audit"]._log) == 1

        # Filing workflow
        assert len(fresh["filing_workflow"]._records) == 1
        assert len(fresh["filing_workflow"]._defect_sheets) == 0

    def test_load_dev_state_returns_false_when_no_file(self, modules, tmp_path):
        fresh = _fresh_modules()
        result = load_dev_state(fresh, str(tmp_path / "nonexistent.json"))
        assert result is False

    def test_round_trip_preserves_all_fields(self, seeded_modules, tmp_path):
        state_path = tmp_path / "state.json"

        save_dev_state(seeded_modules, str(state_path))

        fresh = _fresh_modules()
        load_dev_state(fresh, str(state_path))

        # Compare field-by-field
        orig_auth = seeded_modules["authorization"]
        fresh_auth = fresh["authorization"]
        for uid, orig_user in orig_auth._users.items():
            fresh_user = fresh_auth._users.get(uid)
            assert fresh_user is not None
            assert fresh_user.model_dump() == orig_user.model_dump()
            assert fresh_auth._passwords.get(uid) == orig_auth._passwords.get(uid)

        orig_intake = seeded_modules["application_intake"]
        fresh_intake = fresh["application_intake"]
        for aid, orig_app in orig_intake._store.items():
            fresh_app = fresh_intake._store.get(aid)
            assert fresh_app is not None
            assert fresh_app.model_dump() == orig_app.model_dump()
