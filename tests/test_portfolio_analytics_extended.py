"""Portfolio Analytics extended module-interface tests (local adapter)."""

from app.domain.common import ApplicationStatus
from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.application_intake.models import Disclosure, Inventor
from app.modules.portfolio_analytics.local import LocalPortfolioAnalyticsModule


def _module_with_apps(statuses: list[ApplicationStatus]):
    intake = LocalApplicationIntakeModule()
    for i, status in enumerate(statuses):
        app = intake.create_application_shell(
            Disclosure(
                title=f"Patent {i}",
                inventors=[Inventor(inventor_name="A", inventor_email="a@uni.edu")],
                summary="",
            )
        )
        if status != ApplicationStatus.DRAFT:
            intake.change_status(app.id, status, "admin@blitto.edu")
    return LocalPortfolioAnalyticsModule(application_intake=intake)


def test_portfolio_summary_empty() -> None:
    intake = LocalApplicationIntakeModule()
    analytics = LocalPortfolioAnalyticsModule(application_intake=intake)
    summary = analytics.portfolio_summary()
    assert summary.total_applications == 0


def test_portfolio_summary_counts_single_application() -> None:
    intake = LocalApplicationIntakeModule()
    intake.create_application_shell(
        Disclosure(
            title="X",
            inventors=[Inventor(inventor_name="A", inventor_email="a@uni.edu")],
            summary="",
        )
    )
    analytics = LocalPortfolioAnalyticsModule(application_intake=intake)
    summary = analytics.portfolio_summary()
    assert summary.total_applications == 1
    assert summary.by_status.get("DRAFT", 0) == 1


def test_portfolio_summary_counts_by_status() -> None:
    statuses = [
        ApplicationStatus.DRAFT,
        ApplicationStatus.FILED,
        ApplicationStatus.GRANTED,
        ApplicationStatus.DRAFT,
    ]
    analytics = _module_with_apps(statuses)
    summary = analytics.portfolio_summary()
    assert summary.total_applications == 4
    assert summary.by_status.get("DRAFT", 0) == 2
    assert summary.by_status.get("FILED", 0) == 1
    assert summary.by_status.get("GRANTED", 0) == 1


def test_portfolio_summary_all_statuses_represented() -> None:
    all_statuses = list(ApplicationStatus)
    analytics = _module_with_apps(all_statuses)
    summary = analytics.portfolio_summary()
    assert summary.total_applications == len(all_statuses)
    for status in all_statuses:
        assert summary.by_status.get(status.value, 0) >= 1


def test_portfolio_summary_after_status_change() -> None:
    intake = LocalApplicationIntakeModule()
    app = intake.create_application_shell(
        Disclosure(
            title="X",
            inventors=[Inventor(inventor_name="A", inventor_email="a@uni.edu")],
            summary="",
        )
    )
    analytics = LocalPortfolioAnalyticsModule(application_intake=intake)
    s1 = analytics.portfolio_summary()
    assert s1.by_status.get("DRAFT", 0) == 1

    intake.change_status(app.id, ApplicationStatus.FILED, "admin")
    s2 = analytics.portfolio_summary()
    assert s2.by_status.get("DRAFT", 0) == 0
    assert s2.by_status.get("FILED", 0) == 1
