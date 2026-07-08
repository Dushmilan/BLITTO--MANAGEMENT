"""Application Intake - local in-memory adapter."""

from __future__ import annotations

import uuid
from datetime import datetime, timezone
from typing import Optional

from app.domain.common import ApplicationStatus, StatusHistory
from app.modules.application_intake.interface import ApplicationIntakeModule
from app.modules.application_intake.models import Application, Disclosure


class LocalApplicationIntakeModule:
    def __init__(self) -> None:
        self._store: dict[str, Application] = {}
        self._history: dict[str, list[StatusHistory]] = {}

    def submit_disclosure(self, disclosure: Disclosure) -> Application:
        return self.create_application_shell(disclosure)

    def create_application_shell(self, disclosure: Disclosure) -> Application:
        application = Application(
            id=str(uuid.uuid4()),
            title=disclosure.title,
            inventor_name=disclosure.inventor_name,
            inventor_email=disclosure.inventor_email,
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
