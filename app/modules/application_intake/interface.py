"""Application Intake - module interface.

Deep module per Agent.md: `applicationIntake` (in-process, priority 3).
"""

from __future__ import annotations

from typing import Optional, Protocol

from app.domain.common import ApplicationStatus
from app.modules.application_intake.models import Application, Disclosure


class ApplicationIntakeModule(Protocol):
    def submit_disclosure(self, disclosure: Disclosure) -> Application:
        """Inventor submits an invention disclosure."""
        ...

    def create_application_shell(self, disclosure: Disclosure) -> Application:
        """Paralegal creates an application shell from a disclosure."""
        ...

    def change_status(
        self, application_id: str, new_status: ApplicationStatus, changed_by: str
    ) -> Optional[Application]:
        """Admin-only status transition (domain lifecycle)."""
        ...

    def get_application(self, application_id: str) -> Optional[Application]:
        ...

    def list_applications(self) -> list[Application]:
        ...
