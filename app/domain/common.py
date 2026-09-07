"""Shared domain vocabulary (CONTEXT.md / Readme.md).

No 'Entity'/'DTO'/'Service' naming per Rules.md — these are domain types.
"""

from __future__ import annotations

from datetime import datetime, timezone
from enum import Enum
from typing import Optional

from pydantic import BaseModel, Field


def _now() -> datetime:
    return datetime.now(timezone.utc)


class ApplicationStatus(str, Enum):
    DRAFT = "DRAFT"
    FILED = "FILED"
    PUBLISHED = "PUBLISHED"
    EXAMINATION = "EXAMINATION"
    ACKNOWLEDGED = "ACKNOWLEDGED"
    DEFECT_SHEET_1 = "DEFECT_SHEET_1"
    DEFECT_SHEET_2 = "DEFECT_SHEET_2"
    DEFECT_SHEET_3 = "DEFECT_SHEET_3"
    GRANTED = "GRANTED"
    REJECTED = "REJECTED"
    MAINTENANCE = "MAINTENANCE"


class Role(str, Enum):
    USER = "user"
    DIRECTOR = "director"
    MD = "md"


class AuditLog(BaseModel):
    id: str
    user_id: Optional[str] = None
    application_id: Optional[str] = None
    action: str
    timestamp: datetime = Field(default_factory=_now)
    ip_address: Optional[str] = None


class StatusHistory(BaseModel):
    id: str
    application_id: str
    old_status: Optional[ApplicationStatus] = None
    new_status: ApplicationStatus
    changed_by: str
    changed_at: datetime = Field(default_factory=_now)
