"""Docketing - local in-memory adapter."""

from __future__ import annotations

import uuid
from datetime import datetime
from typing import Optional

from app.modules.docketing.interface import DocketingModule
from app.modules.docketing.models import Deadline, DeadlineStatus, DeadlineType


class LocalDocketingModule:
    def __init__(self) -> None:
        self._store: dict[str, Deadline] = {}

    def add_deadline(
        self, application_id: str, deadline_type: DeadlineType, due_date: datetime
    ) -> Deadline:
        deadline = Deadline(
            id=str(uuid.uuid4()),
            application_id=application_id,
            type=deadline_type,
            due_date=due_date,
        )
        self._store[deadline.id] = deadline
        return deadline

    def list_deadlines(self, application_id: Optional[str] = None) -> list[Deadline]:
        if application_id is None:
            return list(self._store.values())
        return [d for d in self._store.values() if d.application_id == application_id]

    def mark_met(self, deadline_id: str) -> Optional[Deadline]:
        return self._set_status(deadline_id, DeadlineStatus.MET)

    def mark_missed(self, deadline_id: str) -> Optional[Deadline]:
        return self._set_status(deadline_id, DeadlineStatus.MISSED)

    def _set_status(self, deadline_id: str, status: DeadlineStatus) -> Optional[Deadline]:
        deadline = self._store.get(deadline_id)
        if deadline is None:
            return None
        deadline.status = status
        return deadline
