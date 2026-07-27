"""Docketing - domain models."""

from __future__ import annotations

from datetime import datetime, timezone
from enum import Enum
from typing import Optional

from pydantic import BaseModel, Field


def _now() -> datetime:
    return datetime.now(timezone.utc)


class DeadlineType(str, Enum):
    FILING = "FILING"
    RESPONSE = "RESPONSE"
    MAINTENANCE_FEE = "MAINTENANCE_FEE"


class DeadlineStatus(str, Enum):
    OPEN = "OPEN"
    MET = "MET"
    MISSED = "MISSED"


class Deadline(BaseModel):
    id: str
    application_id: str
    type: DeadlineType
    due_date: datetime
    status: DeadlineStatus = DeadlineStatus.OPEN
    created_at: datetime = Field(default_factory=_now)
