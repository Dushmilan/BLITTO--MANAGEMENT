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
        inventors=[Inventor(inventor_name="Dr. Ada Perera", inventor_email="ada@uni.edu")],
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

    result = workflow.mark_filed(app.id, "admin@blitto.edu")

    assert result.status == ApplicationStatus.FILED
    record = workflow.get_filing_record(app.id)
    assert record is not None
    assert record.application_id == app.id
    assert record.filed_by == "admin@blitto.edu"

    sent = notification.sent_history()
    assert len(sent) == 1
    assert sent[0].subject == "Patent Filed"
    assert sent[0].recipient_email == "ada@uni.edu"

    entries = audit.for_application(app.id)
    assert any(e.action == "filing:mark_filed" for e in entries)


def test_acknowledge_nipo_updates_status_and_notifies() -> None:
    app, workflow, _, notification, _ = _fixtures()
    workflow.mark_filed(app.id, "admin@blitto.edu")

    result = workflow.acknowledge_nipo(app.id, "admin@blitto.edu")

    assert result.status == ApplicationStatus.ACKNOWLEDGED
    record = workflow.get_filing_record(app.id)
    assert record is not None
    assert record.nipo_acknowledged_at is not None
    assert record.nipo_acknowledged_by == "admin@blitto.edu"

    sent = notification.sent_history()
    assert any(n.subject == "Acknowledged" for n in sent)
    assert any(n.recipient_email == "ada@uni.edu" and n.subject == "Acknowledged" for n in sent)


def test_defect_sheet_records_and_notifies() -> None:
    app, workflow, _, notification, _ = _fixtures()
    workflow.mark_filed(app.id, "admin@blitto.edu")

    sheet1 = workflow.record_defect_sheet(app.id, 1, "Missing claims section", "admin@blitto.edu")

    assert sheet1.sheet_number == 1
    assert sheet1.description == "Missing claims section"
    sheets = workflow.get_defect_sheets(app.id)
    assert len(sheets) == 1

    sent = notification.sent_history()
    assert any(n.subject == "Defect Sheet Received" for n in sent)
    assert any("Missing claims section" in n.body for n in sent)


def test_defect_sheet_max_three() -> None:
    app, workflow, intake, _, _ = _fixtures()
    workflow.mark_filed(app.id, "admin@blitto.edu")

    workflow.record_defect_sheet(app.id, 1, "First defect", "admin@blitto.edu")
    assert intake.get_application(app.id).status == ApplicationStatus.DEFECT_SHEET_1

    workflow.record_defect_sheet(app.id, 2, "Second defect", "admin@blitto.edu")
    assert intake.get_application(app.id).status == ApplicationStatus.DEFECT_SHEET_2

    workflow.record_defect_sheet(app.id, 3, "Third defect", "admin@blitto.edu")
    assert intake.get_application(app.id).status == ApplicationStatus.DEFECT_SHEET_3

    with pytest.raises(ValueError, match="Maximum 3 defect sheets"):
        workflow.record_defect_sheet(app.id, 4, "Fourth defect", "admin@blitto.edu")
    assert intake.get_application(app.id).status == ApplicationStatus.DEFECT_SHEET_3


def test_mark_granted_updates_status_and_notifies() -> None:
    app, workflow, _, notification, _ = _fixtures()
    workflow.mark_filed(app.id, "admin@blitto.edu")

    result = workflow.mark_granted(app.id, "LK/PAT/2026/00123", "admin@blitto.edu")

    assert result.status == ApplicationStatus.GRANTED
    record = workflow.get_filing_record(app.id)
    assert record is not None
    assert record.patent_number == "LK/PAT/2026/00123"
    assert record.granted_at is not None

    sent = notification.sent_history()
    assert any(n.subject == "Patent Received" for n in sent)


def test_mark_rejected_updates_status_and_notifies() -> None:
    app, workflow, _, notification, audit = _fixtures()
    workflow.mark_filed(app.id, "admin@blitto.edu")

    result = workflow.mark_rejected(app.id, "admin@blitto.edu")

    assert result.status == ApplicationStatus.REJECTED
    record = workflow.get_filing_record(app.id)
    assert record is not None
    assert record.rejected_at is not None
    assert record.rejected_by == "admin@blitto.edu"

    sent = notification.sent_history()
    assert any(n.subject == "Rejected" for n in sent)
    assert any(n.recipient_email == "ada@uni.edu" and n.subject == "Rejected" for n in sent)

    entries = audit.for_application(app.id)
    assert any(e.action == "filing:rejected" for e in entries)
