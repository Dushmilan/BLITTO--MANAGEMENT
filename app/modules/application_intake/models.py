"""Application Intake - domain models."""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Optional

from pydantic import BaseModel, Field, field_validator

from app.core.email_policy import institution_error, is_institution_email, normalize_email
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

    @field_validator("inventor_email")
    @classmethod
    def _single_institution_author(cls, v: str) -> str:
        # One application -> one main author. Reject multi-author payloads.
        if any(sep in v for sep in (",", ";")):
            raise ValueError("One main author only: single inventor_email, no lists")
        v = normalize_email(v)
        if "@" not in v or not is_institution_email(v):
            raise ValueError(institution_error())
        return v

    @field_validator("inventor_name")
    @classmethod
    def _single_author_name(cls, v: str) -> str:
        if any(sep in v for sep in (",", ";")):
            raise ValueError("One main author only: single inventor_name, no lists")
        v = v.strip()
        if not v:
            raise ValueError("inventor_name is required")
        return v


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
