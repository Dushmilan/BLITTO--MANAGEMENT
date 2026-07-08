"""Docketing - module interface.

Deep module per Agent.md: `docketing` (local-substitutable, priority 2).
Single source of truth for deadlines (CONTEXT.md invariant #4).
"""

from __future__ import annotations

from datetime import datetime
from typing import Optional, Protocol

from app.modules.docketing.models import Deadline, DeadlineStatus, DeadlineType


class DocketingModule(Protocol):
    def add_deadline(
        self, application_id: str, deadline_type: DeadlineType, due_date: datetime
    ) -> Deadline:
        ...

    def list_deadlines(self, application_id: Optional[str] = None) -> list[Deadline]:
        ...

    def mark_met(self, deadline_id: str) -> Optional[Deadline]:
        ...

    def mark_missed(self, deadline_id: str) -> Optional[Deadline]:
        ...
