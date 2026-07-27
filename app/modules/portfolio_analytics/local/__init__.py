"""Portfolio Analytics - local in-memory adapter."""

from __future__ import annotations

from collections import Counter

from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.portfolio_analytics.interface import PortfolioAnalyticsModule
from app.modules.portfolio_analytics.models import PortfolioSummary


class LocalPortfolioAnalyticsModule:
    def __init__(
        self,
        application_intake: LocalApplicationIntakeModule,
    ) -> None:
        self._application_intake = application_intake

    def portfolio_summary(self) -> PortfolioSummary:
        applications = self._application_intake.list_applications()
        by_status = Counter(a.status.value for a in applications)
        return PortfolioSummary(
            total_applications=len(applications),
            by_status=dict(by_status),
        )
