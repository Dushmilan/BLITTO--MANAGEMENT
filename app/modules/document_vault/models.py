"""Document Vault - domain models."""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Optional

from pydantic import BaseModel, Field


def _now() -> datetime:
    return datetime.now(timezone.utc)


class Document(BaseModel):
    id: str
    application_id: str
    filename: str
    # Local storage: reference to bytes held in memory or a file path.
    content_ref: str
    uploaded_by: str
    uploaded_at: datetime = Field(default_factory=_now)
