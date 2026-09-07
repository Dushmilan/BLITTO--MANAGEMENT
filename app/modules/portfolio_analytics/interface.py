"""Portfolio Analytics - module interface.

Deep module per Agent.md: `portfolioAnalytics` (local-substitutable, priority 7).
Reads from application_intake + docketing (wired in main.py lifespan).
"""

from __future__ import annotations

from typing import Protocol

from app.modules.portfolio_analytics.models import PortfolioSummary


class PortfolioAnalyticsModule(Protocol):
    def portfolio_summary(self) -> PortfolioSummary:
        ...
