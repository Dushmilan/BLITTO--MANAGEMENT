"""Filing Workflow - local in-memory adapter."""

from __future__ import annotations

import uuid
from datetime import datetime, timezone
from typing import Optional

from app.domain.common import ApplicationStatus
from app.modules.application_intake.interface import ApplicationIntakeModule
from app.modules.audit.interface import AuditModule
from app.modules.filing_workflow.interface import FilingWorkflowModule
from app.modules.filing_workflow.models import FilingRecord
from app.modules.notification.interface import NotificationModule
from app.modules.prosecution.models import DefectSheet


class LocalFilingWorkflowModule:
    def __init__(
        self,
        application_intake: ApplicationIntakeModule,
        notification: NotificationModule,
        audit: AuditModule,
    ) -> None:
        self._intake = application_intake
        self._notification = notification
        self._audit = audit
        self._records: dict[str, FilingRecord] = {}
        self._defect_sheets: dict[str, DefectSheet] = {}

    def mark_filed(self, application_id: str, filed_by: str) -> Application:
        app = self._intake.change_status(application_id, ApplicationStatus.FILED, filed_by)
        if app is None:
            raise ValueError(f"Application {application_id} not found")
        record = FilingRecord(
            id=str(uuid.uuid4()),
            application_id=application_id,
            filed_by=filed_by,
        )
        self._records[application_id] = record
        self._notify_inventors(application_id, "Patent Filed",
                               f"Your patent application {app.title} ({application_id[:8]}) has been filed with NIPO.")
        self._audit.record("filing:mark_filed", user_id=filed_by, application_id=application_id)
        return app

    def acknowledge_nipo(self, application_id: str, acknowledged_by: str) -> Application:
        app = self._intake.change_status(application_id, ApplicationStatus.ACKNOWLEDGED, acknowledged_by)
        if app is None:
            raise ValueError(f"Application {application_id} not found")
        record = self._records.get(application_id)
        if record is None:
            raise ValueError(f"No filing record for {application_id} - mark filed first")
        record.nipo_acknowledged_at = datetime.now(timezone.utc)
        record.nipo_acknowledged_by = acknowledged_by
        self._notify_inventors(application_id, "Acknowledged",
                               f"NIPO has acknowledged receipt of your patent application {app.title} ({application_id[:8]}).")
        self._audit.record("filing:acknowledge_nipo", user_id=acknowledged_by, application_id=application_id)
        return app

    def record_defect_sheet(
        self, application_id: str, sheet_number: int, description: str, recorded_by: str
    ) -> DefectSheet:
        existing = self._get_defect_sheets_for_app(application_id)
        if len(existing) >= 3:
            raise ValueError(f"Maximum 3 defect sheets allowed for {application_id}")
        if any(d.sheet_number == sheet_number for d in existing):
            raise ValueError(f"Defect sheet #{sheet_number} already exists for {application_id}")
        app = self._intake.get_application(application_id)
        if app is None:
            raise ValueError(f"Application {application_id} not found")
        status_key = f"DEFECT_SHEET_{sheet_number}"
        app = self._intake.change_status(
            application_id, ApplicationStatus[status_key], recorded_by
        )
        if app is None:
            raise ValueError(f"Application {application_id} not found")
        sheet = DefectSheet(
            id=str(uuid.uuid4()),
            application_id=application_id,
            sheet_number=sheet_number,
            description=description,
        )
        self._defect_sheets[sheet.id] = sheet
        record = self._records.get(application_id)
        if record is not None:
            record.defect_sheet_count = len(existing) + 1
        self._notify_inventors(application_id, "Defect Sheet Received",
                               f"Defect sheet #{sheet_number} has been received for your patent application {app.title} ({application_id[:8]}): {description}")
        self._audit.record("filing:defect_sheet", user_id=recorded_by, application_id=application_id)
        return sheet

    def mark_granted(
        self, application_id: str, patent_number: str, granted_by: str
    ) -> Application:
        app = self._intake.change_status(application_id, ApplicationStatus.GRANTED, granted_by)
        if app is None:
            raise ValueError(f"Application {application_id} not found")
        record = self._records.get(application_id)
        if record is not None:
            record.granted_at = datetime.now(timezone.utc)
            record.granted_by = granted_by
            record.patent_number = patent_number
        self._notify_inventors(application_id, "Patent Received",
                               f"Your patent {app.title} has been granted with patent number {patent_number}.")
        self._audit.record("filing:granted", user_id=granted_by, application_id=application_id)
        return app

    def mark_rejected(self, application_id: str, rejected_by: str) -> Application:
        app = self._intake.change_status(application_id, ApplicationStatus.REJECTED, rejected_by)
        if app is None:
            raise ValueError(f"Application {application_id} not found")
        record = self._records.get(application_id)
        if record is not None:
            record.rejected_at = datetime.now(timezone.utc)
            record.rejected_by = rejected_by
        self._notify_inventors(application_id, "Rejected",
                               f"Your patent application {app.title} ({application_id[:8]}) has been rejected.")
        self._audit.record("filing:rejected", user_id=rejected_by, application_id=application_id)
        return app

    def get_filing_record(self, application_id: str) -> Optional[FilingRecord]:
        return self._records.get(application_id)

    def get_defect_sheets(self, application_id: str) -> list[DefectSheet]:
        return self._get_defect_sheets_for_app(application_id)

    def get_filed_unacknowledged_apps(self) -> list[tuple[Application, FilingRecord]]:
        result: list[tuple[Application, FilingRecord]] = []
        for app_id, record in self._records.items():
            if record.nipo_acknowledged_at is not None:
                continue
            app = self._intake.get_application(app_id)
            if app is not None and app.status == ApplicationStatus.FILED:
                result.append((app, record))
        return result

    def _get_defect_sheets_for_app(self, application_id: str) -> list[DefectSheet]:
        return [d for d in self._defect_sheets.values() if d.application_id == application_id]

    def _notify_inventors(self, application_id: str, subject: str, body: str) -> None:
        app = self._intake.get_application(application_id)
        if app is None:
            return
        inventors = self._intake.get_inventors_for_application(application_id)
        for inv in inventors:
            self._notification.send_notification(inv.inventor_email, subject, body)
