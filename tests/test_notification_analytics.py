"""Notification + Portfolio Analytics module-interface tests (local adapters)."""

from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.application_intake.models import Disclosure
from app.modules.docketing.local import LocalDocketingModule
from app.modules.notification.local import LocalNotificationModule
from app.modules.portfolio_analytics.local import LocalPortfolioAnalyticsModule


def test_notification_mocked() -> None:
    note = LocalNotificationModule()
    sent = note.send_status_change("a@uni.edu", "app-1", "EXAMINATION")
    assert sent.recipient_email == "a@uni.edu"
    assert len(note.sent_history()) == 1


def test_send_notification_and_get_for_recipient() -> None:
    note = LocalNotificationModule()
    sent = note.send_notification("b@uni.edu", "Subject", "Body")
    assert sent.recipient_email == "b@uni.edu"
    assert sent.subject == "Subject"
    received = note.get_for_recipient("b@uni.edu")
    assert len(received) == 1
    assert received[0].id == sent.id


def test_portfolio_summary_and_deadlines() -> None:
    intake = LocalApplicationIntakeModule()
    intake.create_application_shell(
        Disclosure(inventor_name="A", inventor_email="a@uni.edu", title="X", summary="s")
    )
    docketing = LocalDocketingModule()
    analytics = LocalPortfolioAnalyticsModule(application_intake=intake, docketing=docketing)
    summary = analytics.portfolio_summary()
    assert summary.total_applications == 1
    report = analytics.deadline_report()
    assert report.open_deadlines == 0
