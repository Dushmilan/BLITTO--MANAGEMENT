"""Filing Workflow - domain models."""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Optional

from pydantic import BaseModel, Field


def _now() -> datetime:
    return datetime.now(timezone.utc)


class FilingRecord(BaseModel):
    id: str
    application_id: str
    filed_date: datetime = Field(default_factory=_now)
    filed_by: str = ""
    nipo_acknowledged_at: Optional[datetime] = None
    nipo_acknowledged_by: Optional[str] = None
    defect_sheet_count: int = 0
    granted_at: Optional[datetime] = None
    granted_by: Optional[str] = None
    patent_number: Optional[str] = None
    rejected_at: Optional[datetime] = None
    rejected_by: Optional[str] = None
