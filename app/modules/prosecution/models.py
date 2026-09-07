"""Prosecution - domain models."""

from __future__ import annotations

from datetime import datetime, timezone
from enum import Enum

from pydantic import BaseModel, Field


def _now() -> datetime:
    return datetime.now(timezone.utc)


class DefectSheet(BaseModel):
    id: str
    application_id: str
    sheet_number: int
    description: str


class OfficeActionKind(str, Enum):
    REJECTION = "REJECTION"
    OBJECTION = "OBJECTION"
    ALLOWANCE = "ALLOWANCE"


class OfficeAction(BaseModel):
    id: str
    application_id: str
    kind: OfficeActionKind
    body: str
    received_at: datetime = Field(default_factory=_now)


class Response(BaseModel):
    id: str
    application_id: str
    office_action_id: str
    body: str
    filed_at: datetime = Field(default_factory=_now)
