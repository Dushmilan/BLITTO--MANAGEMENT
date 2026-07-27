"""Application Intake - domain models."""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Optional

from pydantic import BaseModel, Field

from app.domain.common import ApplicationStatus


def _now() -> datetime:
    return datetime.now(timezone.utc)


class Inventor(BaseModel):
    inventor_name: str
    inventor_email: str


class Disclosure(BaseModel):
    inventor_name: str = ""
    inventor_email: str = ""
    title: str
    summary: str = ""
    inventors: list[Inventor] = []
    technology_area: str = ""


class Application(BaseModel):
    id: str
    title: str
    inventor_name: str = ""
    inventor_email: str = ""
    inventors: list[Inventor] = []
    status: ApplicationStatus = ApplicationStatus.DRAFT
    application_number: Optional[str] = None
    nipo_reference: Optional[str] = None
    technology_area: str = ""
    created_at: datetime = Field(default_factory=_now)
    updated_at: datetime = Field(default_factory=_now)
