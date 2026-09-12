"""Filing Workflow module-interface tests (local adapter)."""

import pytest

from app.domain.common import ApplicationStatus
from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.application_intake.models import Disclosure, Inventor
from app.modules.audit.local import LocalAuditModule
from app.modules.filing_workflow.local import LocalFilingWorkflowModule
from app.modules.notification.local import LocalNotificationModule


def _fixtures():
    intake = LocalApplicationIntakeModule()
    disclosure = Disclosure(
        title="Solar Desalination Membrane",
        inventors=[Inventor(inventor_name="Dr. Ada Perera", inventor_email="ada@pdn.ac.lk")],
        summary="A low-cost membrane for solar-powered desalination.",
    )
    app = intake.create_application_shell(disclosure)
    notification = LocalNotificationModule()
    audit = LocalAuditModule()
    workflow = LocalFilingWorkflowModule(
        application_intake=intake,
        notification=notification,
        audit=audit,
    )
    return app, workflow, intake, notification, audit


def test_mark_filed_creates_filing_record_and_notifies() -> None:
    app, workflow, intake, notification, audit = _fixtures()

    result = workflow.mark_filed(app.id, "md@pdn.ac.lk")

    assert result.status == ApplicationStatus.FILED
    record = workflow.get_filing_record(app.id)
    assert record is not None
    assert record.application_id == app.id
    assert record.filed_by == "md@pdn.ac.lk"

    sent = notification.sent_history()
    assert len(sent) == 1
    assert sent[0].subject == "Patent Filed"
    assert sent[0].recipient_email == "ada@pdn.ac.lk"

    entries = audit.for_application(app.id)
    assert any(e.action == "filing:mark_filed" for e in entries)


def test_acknowledge_nipo_updates_status_and_notifies() -> None:
    app, workflow, _, notification, _ = _fixtures()
    workflow.mark_filed(app.id, "md@pdn.ac.lk")

    result = workflow.acknowledge_nipo(app.id, "md@pdn.ac.lk")

    assert result.status == ApplicationStatus.ACKNOWLEDGED
    record = workflow.get_filing_record(app.id)
    assert record is not None
    assert record.nipo_acknowledged_at is not None
    assert record.nipo_acknowledged_by == "md@pdn.ac.lk"

    sent = notification.sent_history()
    assert any(n.subject == "Acknowledged" for n in sent)
    assert any(n.recipient_email == "ada@pdn.ac.lk" and n.subject == "Acknowledged" for n in sent)


def test_defect_sheet_records_and_notifies() -> None:
    app, workflow, _, notification, _ = _fixtures()
    workflow.mark_filed(app.id, "md@pdn.ac.lk")

    sheet1 = workflow.record_defect_sheet(app.id, 1, "Missing claims section", "md@pdn.ac.lk")

    assert sheet1.sheet_number == 1
    assert sheet1.description == "Missing claims section"
    sheets = workflow.get_defect_sheets(app.id)
    assert len(sheets) == 1

    sent = notification.sent_history()
    assert any(n.subject == "Defect Sheet Received" for n in sent)
    assert any("Missing claims section" in n.body for n in sent)


def test_defect_sheet_max_three() -> None:
    app, workflow, intake, _, _ = _fixtures()
    workflow.mark_filed(app.id, "md@pdn.ac.lk")

    workflow.record_defect_sheet(app.id, 1, "First defect", "md@pdn.ac.lk")
    assert intake.get_application(app.id).status == ApplicationStatus.DEFECT_SHEET_1

    workflow.record_defect_sheet(app.id, 2, "Second defect", "md@pdn.ac.lk")
    assert intake.get_application(app.id).status == ApplicationStatus.DEFECT_SHEET_2

    workflow.record_defect_sheet(app.id, 3, "Third defect", "md@pdn.ac.lk")
    assert intake.get_application(app.id).status == ApplicationStatus.DEFECT_SHEET_3

    with pytest.raises(ValueError, match="must be 1-3"):
        workflow.record_defect_sheet(app.id, 4, "Fourth defect", "md@pdn.ac.lk")
    assert intake.get_application(app.id).status == ApplicationStatus.DEFECT_SHEET_3

    with pytest.raises(ValueError, match="already exists"):
        workflow.record_defect_sheet(app.id, 1, "Duplicate defect", "md@pdn.ac.lk")


def test_mark_granted_updates_status_and_notifies() -> None:
    app, workflow, intake, notification, _ = _fixtures()
    workflow.mark_filed(app.id, "md@pdn.ac.lk")
    intake.change_status(app.id, ApplicationStatus.EXAMINATION, "md@pdn.ac.lk")

    result = workflow.mark_granted(app.id, "LK/PAT/2026/00123", "md@pdn.ac.lk")

    assert result.status == ApplicationStatus.GRANTED
    record = workflow.get_filing_record(app.id)
    assert record is not None
    assert record.patent_number == "LK/PAT/2026/00123"
    assert record.granted_at is not None

    sent = notification.sent_history()
    assert any(n.subject == "Patent Received" for n in sent)


def test_mark_rejected_updates_status_and_notifies() -> None:
    app, workflow, _, notification, audit = _fixtures()
    workflow.mark_filed(app.id, "md@pdn.ac.lk")

    result = workflow.mark_rejected(app.id, "md@pdn.ac.lk")

    assert result.status == ApplicationStatus.REJECTED
    record = workflow.get_filing_record(app.id)
    assert record is not None
    assert record.rejected_at is not None
    assert record.rejected_by == "md@pdn.ac.lk"

    sent = notification.sent_history()
    assert any(n.subject == "Rejected" for n in sent)
    assert any(n.recipient_email == "ada@pdn.ac.lk" and n.subject == "Rejected" for n in sent)

    entries = audit.for_application(app.id)
    assert any(e.action == "filing:rejected" for e in entries)


def test_acknowledge_nipo_notifies_all_inventors() -> None:
    intake = LocalApplicationIntakeModule()
    disclosure = Disclosure(
        title="Graphene Battery Anode",
        inventors=[
            Inventor(inventor_name="Dr. Ada Perera", inventor_email="ada@pdn.ac.lk"),
            Inventor(inventor_name="Dr. Bob Chen", inventor_email="bob@pdn.ac.lk"),
        ],
        summary="High-capacity graphene-based battery anode.",
    )
    app = intake.create_application_shell(disclosure)
    notification = LocalNotificationModule()
    audit = LocalAuditModule()
    workflow = LocalFilingWorkflowModule(
        application_intake=intake,
        notification=notification,
        audit=audit,
    )

    workflow.mark_filed(app.id, "md@pdn.ac.lk")
    result = workflow.acknowledge_nipo(app.id, "md@pdn.ac.lk")

    assert result.status == ApplicationStatus.ACKNOWLEDGED
    record = workflow.get_filing_record(app.id)
    assert record is not None
    assert record.nipo_acknowledged_at is not None
    assert record.nipo_acknowledged_by == "md@pdn.ac.lk"

    ack_notifications = [n for n in notification.sent_history() if n.subject == "Acknowledged"]
    assert len(ack_notifications) == 2

    emails = {n.recipient_email for n in ack_notifications}
    assert emails == {"ada@pdn.ac.lk", "bob@pdn.ac.lk"}

    expected_body = (
        f"NIPO has acknowledged receipt of your patent application "
        f"{app.title} ({app.id[:8]})."
    )
    for n in ack_notifications:
        assert n.body == expected_body

    entries = audit.for_application(app.id)
    assert any(e.action == "filing:acknowledge_nipo" for e in entries)


def test_acknowledge_only_notifies_own_inventors() -> None:
    intake = LocalApplicationIntakeModule()
    disclosure_a = Disclosure(
        title="Graphene Battery Anode",
        inventors=[
            Inventor(inventor_name="Alice", inventor_email="alice@pdn.ac.lk"),
            Inventor(inventor_name="Bob", inventor_email="bob@pdn.ac.lk"),
        ],
        summary="App A",
    )
    disclosure_b = Disclosure(
        title="Quantum Sensor Array",
        inventors=[
            Inventor(inventor_name="Charlie", inventor_email="charlie@pdn.ac.lk"),
            Inventor(inventor_name="Dave", inventor_email="dave@pdn.ac.lk"),
        ],
        summary="App B",
    )
    app_a = intake.create_application_shell(disclosure_a)
    app_b = intake.create_application_shell(disclosure_b)
    notification = LocalNotificationModule()
    audit = LocalAuditModule()
    workflow = LocalFilingWorkflowModule(
        application_intake=intake,
        notification=notification,
        audit=audit,
    )

    workflow.mark_filed(app_a.id, "md@pdn.ac.lk")
    workflow.mark_filed(app_b.id, "md@pdn.ac.lk")
    workflow.acknowledge_nipo(app_a.id, "md@pdn.ac.lk")

    ack_notifications = [n for n in notification.sent_history() if n.subject == "Acknowledged"]
    assert len(ack_notifications) == 2

    emails = {n.recipient_email for n in ack_notifications}
    assert emails == {"alice@pdn.ac.lk", "bob@pdn.ac.lk"}


def test_acknowledge_empty_inventors_falls_back_to_single() -> None:
    intake = LocalApplicationIntakeModule()
    disclosure = Disclosure(
        title="Empty Inventors Test",
        inventors=[],
        inventor_name="Fallback Inventor",
        inventor_email="fallback@pdn.ac.lk",
        summary="No explicit inventors — uses fallback fields.",
    )
    app = intake.create_application_shell(disclosure)
    notification = LocalNotificationModule()
    workflow = LocalFilingWorkflowModule(
        application_intake=intake,
        notification=notification,
        audit=LocalAuditModule(),
    )
    workflow.mark_filed(app.id, "md@pdn.ac.lk")
    workflow.acknowledge_nipo(app.id, "md@pdn.ac.lk")

    ack_notifications = [n for n in notification.sent_history() if n.subject == "Acknowledged"]
    assert len(ack_notifications) == 1
    assert ack_notifications[0].recipient_email == "fallback@pdn.ac.lk"


def test_acknowledge_duplicate_inventor_email() -> None:
    intake = LocalApplicationIntakeModule()
    disclosure = Disclosure(
        title="Dup Email Test",
        inventors=[
            Inventor(inventor_name="Alice", inventor_email="alice@pdn.ac.lk"),
            Inventor(inventor_name="Alice Clone", inventor_email="alice@pdn.ac.lk"),
        ],
        summary="Same email twice in inventors list.",
    )
    app = intake.create_application_shell(disclosure)
    notification = LocalNotificationModule()
    workflow = LocalFilingWorkflowModule(
        application_intake=intake,
        notification=notification,
        audit=LocalAuditModule(),
    )
    workflow.mark_filed(app.id, "md@pdn.ac.lk")
    workflow.acknowledge_nipo(app.id, "md@pdn.ac.lk")

    ack_notifications = [n for n in notification.sent_history() if n.subject == "Acknowledged"]
    assert len(ack_notifications) == 2
    assert all(n.recipient_email == "alice@pdn.ac.lk" for n in ack_notifications)


def test_acknowledge_without_filing() -> None:
    intake = LocalApplicationIntakeModule()
    disclosure = Disclosure(
        title="No Filing Test",
        inventors=[Inventor(inventor_name="Alice", inventor_email="alice@pdn.ac.lk")],
        summary="Acknowledge without filing first.",
    )
    app = intake.create_application_shell(disclosure)
    notification = LocalNotificationModule()
    workflow = LocalFilingWorkflowModule(
        application_intake=intake,
        notification=notification,
        audit=LocalAuditModule(),
    )

    with pytest.raises(ValueError, match="No filing record"):
        workflow.acknowledge_nipo(app.id, "md@pdn.ac.lk")

    assert len(notification.sent_history()) == 0


def test_acknowledge_nonexistent_app() -> None:
    intake = LocalApplicationIntakeModule()
    notification = LocalNotificationModule()
    workflow = LocalFilingWorkflowModule(
        application_intake=intake,
        notification=notification,
        audit=LocalAuditModule(),
    )

    with pytest.raises(ValueError, match="No filing record"):
        workflow.acknowledge_nipo("nonexistent-id", "md@pdn.ac.lk")


def test_double_acknowledge_sends_notifications_twice() -> None:
    intake = LocalApplicationIntakeModule()
    disclosure = Disclosure(
        title="Double Ack Test",
        inventors=[
            Inventor(inventor_name="Alice", inventor_email="alice@pdn.ac.lk"),
            Inventor(inventor_name="Bob", inventor_email="bob@pdn.ac.lk"),
        ],
        summary="Acknowledge twice.",
    )
    app = intake.create_application_shell(disclosure)
    notification = LocalNotificationModule()
    workflow = LocalFilingWorkflowModule(
        application_intake=intake,
        notification=notification,
        audit=LocalAuditModule(),
    )
    workflow.mark_filed(app.id, "md@pdn.ac.lk")

    workflow.acknowledge_nipo(app.id, "md@pdn.ac.lk")
    workflow.acknowledge_nipo(app.id, "md@pdn.ac.lk")

    ack_notifications = [n for n in notification.sent_history() if n.subject == "Acknowledged"]
    assert len(ack_notifications) == 4
    emails = [n.recipient_email for n in ack_notifications]
    assert emails.count("alice@pdn.ac.lk") == 2
    assert emails.count("bob@pdn.ac.lk") == 2


def test_same_inventor_on_two_apps_both_acknowledged() -> None:
    intake = LocalApplicationIntakeModule()
    disclosure_a = Disclosure(
        title="App A",
        inventors=[
            Inventor(inventor_name="Alice", inventor_email="alice@pdn.ac.lk"),
            Inventor(inventor_name="Bob", inventor_email="bob@pdn.ac.lk"),
        ],
        summary="App A",
    )
    disclosure_b = Disclosure(
        title="App B",
        inventors=[
            Inventor(inventor_name="Alice", inventor_email="alice@pdn.ac.lk"),
            Inventor(inventor_name="Charlie", inventor_email="charlie@pdn.ac.lk"),
        ],
        summary="App B",
    )
    app_a = intake.create_application_shell(disclosure_a)
    app_b = intake.create_application_shell(disclosure_b)
    notification = LocalNotificationModule()
    workflow = LocalFilingWorkflowModule(
        application_intake=intake,
        notification=notification,
        audit=LocalAuditModule(),
    )
    workflow.mark_filed(app_a.id, "md@pdn.ac.lk")
    workflow.mark_filed(app_b.id, "md@pdn.ac.lk")

    workflow.acknowledge_nipo(app_a.id, "md@pdn.ac.lk")
    workflow.acknowledge_nipo(app_b.id, "md@pdn.ac.lk")

    ack_notifications = [n for n in notification.sent_history() if n.subject == "Acknowledged"]
    assert len(ack_notifications) == 4

    alice_notifs = [n for n in ack_notifications if n.recipient_email == "alice@pdn.ac.lk"]
    bob_notifs = [n for n in ack_notifications if n.recipient_email == "bob@pdn.ac.lk"]
    charlie_notifs = [n for n in ack_notifications if n.recipient_email == "charlie@pdn.ac.lk"]

    assert len(alice_notifs) == 2
    assert len(bob_notifs) == 1
    assert len(charlie_notifs) == 1
