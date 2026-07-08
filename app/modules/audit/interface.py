"""Audit - module interface.

Deep-module seam per CONTEXT.md invariant #5: every mutation is logged with
actor + timestamp. Local in-memory adapter for the skeleton.
"""

from __future__ import annotations

from typing import Optional, Protocol

from app.domain.common import AuditLog


class AuditModule(Protocol):
    def record(
        self,
        action: str,
        user_id: Optional[str] = None,
        application_id: Optional[str] = None,
        ip_address: Optional[str] = None,
    ) -> AuditLog:
        ...

    def for_application(self, application_id: str) -> list[AuditLog]:
        ...
