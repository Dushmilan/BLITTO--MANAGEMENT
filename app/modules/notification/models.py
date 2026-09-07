"""Notification - domain models."""

from __future__ import annotations

from datetime import datetime, timezone

from pydantic import BaseModel, Field


def _now() -> datetime:
    return datetime.now(timezone.utc)


class Notification(BaseModel):
    id: str
    recipient_email: str
    subject: str
    body: str
    kind: str = "status_change"
    sent_at: datetime = Field(default_factory=_now)
