"""Application Intake - local in-memory adapter."""

from __future__ import annotations

import uuid
from datetime import datetime, timezone
from typing import Optional

from app.domain.common import ApplicationStatus, StatusHistory
from app.modules.application_intake.interface import ApplicationIntakeModule
from app.modules.application_intake.models import Application, Disclosure, Inventor


class InvalidTransitionError(ValueError):
    """Raised when a status change violates the lifecycle (e.g. DRAFT->GRANTED)."""


# Canonical prosecution lifecycle. Same-status re-entry is always allowed
# (idempotent); everything else must follow the table.
_ALLOWED_TRANSITIONS: dict[ApplicationStatus, frozenset[ApplicationStatus]] = {
    ApplicationStatus.DRAFT: frozenset({ApplicationStatus.FILED}),
    ApplicationStatus.FILED: frozenset({
        ApplicationStatus.PUBLISHED,
        ApplicationStatus.ACKNOWLEDGED,
        ApplicationStatus.EXAMINATION,
        ApplicationStatus.DEFECT_SHEET_1,
        ApplicationStatus.REJECTED,
    }),
    ApplicationStatus.PUBLISHED: frozenset({
        ApplicationStatus.EXAMINATION,
        ApplicationStatus.ACKNOWLEDGED,
        ApplicationStatus.REJECTED,
    }),
    ApplicationStatus.ACKNOWLEDGED: frozenset({
        ApplicationStatus.EXAMINATION,
        ApplicationStatus.DEFECT_SHEET_1,
        ApplicationStatus.DEFECT_SHEET_2,
        ApplicationStatus.DEFECT_SHEET_3,
        ApplicationStatus.GRANTED,
        ApplicationStatus.REJECTED,
    }),
    ApplicationStatus.DEFECT_SHEET_1: frozenset({
        ApplicationStatus.DEFECT_SHEET_2,
        ApplicationStatus.ACKNOWLEDGED,
        ApplicationStatus.EXAMINATION,
        ApplicationStatus.REJECTED,
    }),
    ApplicationStatus.DEFECT_SHEET_2: frozenset({
        ApplicationStatus.DEFECT_SHEET_3,
        ApplicationStatus.ACKNOWLEDGED,
        ApplicationStatus.EXAMINATION,
        ApplicationStatus.REJECTED,
    }),
    ApplicationStatus.DEFECT_SHEET_3: frozenset({
        ApplicationStatus.ACKNOWLEDGED,
        ApplicationStatus.EXAMINATION,
        ApplicationStatus.REJECTED,
    }),
    ApplicationStatus.EXAMINATION: frozenset({
        ApplicationStatus.GRANTED,
        ApplicationStatus.REJECTED,
        ApplicationStatus.ACKNOWLEDGED,
    }),
    ApplicationStatus.GRANTED: frozenset({ApplicationStatus.MAINTENANCE}),
    ApplicationStatus.REJECTED: frozenset(),
    ApplicationStatus.MAINTENANCE: frozenset(),
}


class LocalApplicationIntakeModule:
    def __init__(self) -> None:
        self._store: dict[str, Application] = {}
        self._history: dict[str, list[StatusHistory]] = {}

    def submit_disclosure(self, disclosure: Disclosure) -> Application:
        return self.create_application_shell(disclosure)

    def create_application_shell(self, disclosure: Disclosure) -> Application:
        inventors = disclosure.inventors
        if not inventors:
            inventors = [Inventor(
                inventor_name=disclosure.inventor_name,
                inventor_email=disclosure.inventor_email,
            )]
        application = Application(
            id=str(uuid.uuid4()),
            title=disclosure.title,
            inventors=inventors,
            inventor_name=inventors[0].inventor_name,
            inventor_email=inventors[0].inventor_email,
            technology_area=disclosure.technology_area,
        )
        self._store[application.id] = application
        return application

    def change_status(
        self, application_id: str, new_status: ApplicationStatus, changed_by: str
    ) -> Optional[Application]:
        application = self._store.get(application_id)
        if application is None:
            return None
        old = application.status
        if new_status != old and new_status not in _ALLOWED_TRANSITIONS[old]:
            raise InvalidTransitionError(
                f"Illegal transition {old.value}->{new_status.value}"
            )
        application.status = new_status
        application.updated_at = datetime.now(timezone.utc)
        self._history.setdefault(application_id, []).append(
            StatusHistory(
                id=str(uuid.uuid4()),
                application_id=application_id,
                old_status=old,
                new_status=new_status,
                changed_by=changed_by,
            )
        )
        return application

    def get_application(self, application_id: str) -> Optional[Application]:
        return self._store.get(application_id)

    def list_applications(self) -> list[Application]:
        return list(self._store.values())

    def get_inventors_for_application(self, application_id: str) -> list[Inventor]:
        app = self._store.get(application_id)
        if app is None:
            return []
        return app.inventors

    def get_applications_for_inventor(self, email: str) -> list[Application]:
        return [a for a in self._store.values()
                if any(inv.inventor_email == email for inv in a.inventors)]
