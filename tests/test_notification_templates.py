"""TDD RED: per-status notification designs."""

from app.modules.notification.local import LocalNotificationModule


def test_granted_notification_has_download_wording_and_kind() -> None:
    note = LocalNotificationModule()
    sent = note.send_status_change("a@pdn.ac.lk", "app-1", "GRANTED")
    assert sent.kind == "status_change"
    assert "GRANTED" in sent.subject or "GRANTED" in sent.body
    assert "download" in sent.body.lower()


def test_granted_pending_doc_notification_variant() -> None:
    note = LocalNotificationModule()
    sent = note.send_status_change(
        "a@pdn.ac.lk", "app-1", "GRANTED", document_available=False
    )
    assert "pending" in sent.body.lower() or "request" in sent.body.lower()


def test_admin_warning_on_grant_without_document() -> None:
    note = LocalNotificationModule()
    sent = note.send_admin_warning("md@pdn.ac.lk", "app-1")
    assert sent.kind == "grant_without_document"
    assert sent.recipient_email == "md@pdn.ac.lk"


def test_document_request_notification() -> None:
    note = LocalNotificationModule()
    sent = note.send_document_request(
        "md@pdn.ac.lk", "app-1", "a@pdn.ac.lk"
    )
    assert sent.kind == "document_request"
    assert "a@pdn.ac.lk" in sent.body


def test_download_ready_notification() -> None:
    note = LocalNotificationModule()
    sent = note.send_download_ready("a@pdn.ac.lk", "app-1")
    assert sent.kind == "download_ready"
    assert "download" in sent.body.lower()
