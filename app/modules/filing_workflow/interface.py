"""Filing Workflow - module interface.

Deep module per Agent.md: `filingWorkflow` (in-process, priority 4).
Orchestrates the patent filing lifecycle: file → NIPO ack → defect sheets → grant.
"""

from __future__ import annotations

from typing import Optional, Protocol

from app.modules.application_intake.models import Application
from app.modules.filing_workflow.models import FilingRecord
from app.modules.prosecution.models import DefectSheet


class FilingWorkflowModule(Protocol):
    def mark_filed(self, application_id: str, filed_by: str) -> Application:
        ...

    def acknowledge_nipo(self, application_id: str, acknowledged_by: str) -> Application:
        ...

    def record_defect_sheet(
        self, application_id: str, sheet_number: int, description: str, recorded_by: str
    ) -> DefectSheet:
        ...

    def mark_granted(
        self, application_id: str, patent_number: str, granted_by: str
    ) -> Application:
        ...

    def mark_rejected(self, application_id: str, rejected_by: str) -> Application:
        ...

    def get_filing_record(self, application_id: str) -> Optional[FilingRecord]:
        ...

    def get_defect_sheets(self, application_id: str) -> list[DefectSheet]:
        ...

    def get_filed_unacknowledged_apps(self) -> list[tuple[Application, FilingRecord]]:
        ...
