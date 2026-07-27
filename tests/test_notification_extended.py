"""Notification extended module-interface tests (local adapter)."""

from app.modules.notification.local import LocalNotificationModule


def _module() -> LocalNotificationModule:
    return LocalNotificationModule()


def test_send_notification_to_multiple_recipients() -> None:
    module = _module()
    n1 = module.send_notification("a@uni.edu", "Subject A", "Body A")
    n2 = module.send_notification("b@uni.edu", "Subject B", "Body B")
    all_sent = module.sent_history()
    assert len(all_sent) == 2


def test_get_for_recipient_empty_when_none() -> None:
    module = _module()
    assert module.get_for_recipient("ghost@uni.edu") == []


def test_sent_history_empty_when_none_sent() -> None:
    module = _module()
    assert module.sent_history() == []


def test_notification_has_unique_ids() -> None:
    module = _module()
    n1 = module.send_notification("a@uni.edu", "S1", "B1")
    n2 = module.send_notification("b@uni.edu", "S2", "B2")
    assert n1.id != n2.id


def test_send_notification_with_empty_body() -> None:
    module = _module()
    n = module.send_notification("a@uni.edu", "Empty Body", "")
    assert n.body == ""
    assert n.recipient_email == "a@uni.edu"


def test_send_status_change_creates_notification() -> None:
    module = _module()
    n = module.send_status_change("inv@uni.edu", "app-1", "EXAMINATION")
    assert n.recipient_email == "inv@uni.edu"
    assert "EXAMINATION" in n.body


def test_get_for_recipient_filters_correctly() -> None:
    module = _module()
    module.send_notification("a@uni.edu", "S1", "B1")
    module.send_notification("b@uni.edu", "S2", "B2")
    a_notifs = module.get_for_recipient("a@uni.edu")
    assert len(a_notifs) == 1
    assert a_notifs[0].recipient_email == "a@uni.edu"
