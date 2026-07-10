"""Audit - local in-memory adapter (CONTEXT.md invariant #5)."""

from __future__ import annotations

import uuid
from datetime import datetime
from typing import Optional

from app.domain.common import AuditLog
from app.modules.audit.interface import AuditModule


class LocalAuditModule:
    def __init__(self) -> None:
        self._log: dict[str, AuditLog] = {}

    def record(
        self,
        action: str,
        user_id: Optional[str] = None,
        application_id: Optional[str] = None,
        ip_address: Optional[str] = None,
    ) -> AuditLog:
        entry = AuditLog(
            id=str(uuid.uuid4()),
            user_id=user_id,
            application_id=application_id,
            action=action,
            ip_address=ip_address,
        )
        self._log[entry.id] = entry
        return entry

    def for_application(self, application_id: str) -> list[AuditLog]:
        return [e for e in self._log.values() if e.application_id == application_id]

    def by_actor(self, user_id: str) -> list[AuditLog]:
        return [e for e in self._log.values() if e.user_id == user_id]

    def by_action(self, action: str) -> list[AuditLog]:
        return [e for e in self._log.values() if e.action == action]

    def by_date_range(self, start: datetime, end: datetime) -> list[AuditLog]:
        return [
            e for e in self._log.values()
            if start <= e.timestamp <= end
        ]
