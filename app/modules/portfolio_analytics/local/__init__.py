"""Portfolio Analytics - local in-memory adapter."""

from __future__ import annotations

from collections import Counter

from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.docketing.local import LocalDocketingModule
from app.modules.portfolio_analytics.interface import PortfolioAnalyticsModule
from app.modules.portfolio_analytics.models import DeadlineReport, PortfolioSummary


class LocalPortfolioAnalyticsModule:
    def __init__(
        self,
        application_intake: LocalApplicationIntakeModule,
        docketing: LocalDocketingModule,
    ) -> None:
        self._application_intake = application_intake
        self._docketing = docketing

    def portfolio_summary(self) -> PortfolioSummary:
        applications = self._application_intake.list_applications()
        by_status = Counter(a.status.value for a in applications)
        return PortfolioSummary(
            total_applications=len(applications),
            by_status=dict(by_status),
        )

    def deadline_report(self) -> DeadlineReport:
        deadlines = self._docketing.list_deadlines()
        open_count = sum(1 for d in deadlines if d.status.value == "OPEN")
        by_type = Counter(d.type.value for d in deadlines)
        return DeadlineReport(open_deadlines=open_count, by_type=dict(by_type))
