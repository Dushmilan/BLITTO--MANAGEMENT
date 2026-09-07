"""Portfolio Analytics - domain models."""

from __future__ import annotations

from pydantic import BaseModel


class PortfolioSummary(BaseModel):
    total_applications: int
    by_status: dict[str, int]


class DeadlineReport(BaseModel):
    open_deadlines: int
    by_type: dict[str, int]



