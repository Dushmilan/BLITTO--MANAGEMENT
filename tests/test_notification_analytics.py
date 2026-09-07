"""Notification + Portfolio Analytics module-interface tests (local adapters)."""

from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.application_intake.models import Disclosure
from app.modules.notification.local import LocalNotificationModule
from app.modules.docketing.local import LocalDocketingModule
from app.modules.portfolio_analytics.local import LocalPortfolioAnalyticsModule


def test_notification_mocked() -> None:
    note = LocalNotificationModule()
    sent = note.send_status_change("a@pdn.ac.lk", "app-1", "EXAMINATION")
    assert sent.recipient_email == "a@pdn.ac.lk"
    assert len(note.sent_history()) == 1


def test_send_notification_and_get_for_recipient() -> None:
    note = LocalNotificationModule()
    sent = note.send_notification("b@pdn.ac.lk", "Subject", "Body")
    assert sent.recipient_email == "b@pdn.ac.lk"
    assert sent.subject == "Subject"
    received = note.get_for_recipient("b@pdn.ac.lk")
    assert len(received) == 1
    assert received[0].id == sent.id


def test_portfolio_summary() -> None:
    intake = LocalApplicationIntakeModule()
    intake.create_application_shell(
        Disclosure(inventor_name="A", inventor_email="a@pdn.ac.lk", title="X", summary="s")
    )
    analytics = LocalPortfolioAnalyticsModule(docketing=LocalDocketingModule(), application_intake=intake)
    summary = analytics.portfolio_summary()
    assert summary.total_applications == 1
