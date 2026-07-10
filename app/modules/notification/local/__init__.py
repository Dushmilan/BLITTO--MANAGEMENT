"""Notification - local console/mock adapter (true-external seam mock)."""

from __future__ import annotations

import uuid

from app.modules.notification.interface import NotificationModule
from app.modules.notification.models import Notification


class LocalNotificationModule:
    def __init__(self) -> None:
        self._sent: list[Notification] = []

    def send_status_change(
        self, recipient_email: str, application_ref: str, new_status: str
    ) -> Notification:
        notification = Notification(
            id=str(uuid.uuid4()),
            recipient_email=recipient_email,
            subject=f"Patent {application_ref} status changed",
            body=f"The patent application {application_ref} is now: {new_status}.",
        )
        self._sent.append(notification)
        # Local mock: print instead of sending.
        print(f"[notification:mock] -> {recipient_email}: {notification.subject}")
        return notification

    def send_notification(
        self, recipient_email: str, subject: str, body: str
    ) -> Notification:
        notification = Notification(
            id=str(uuid.uuid4()),
            recipient_email=recipient_email,
            subject=subject,
            body=body,
        )
        self._sent.append(notification)
        print(f"[notification:mock] -> {recipient_email}: {subject}")
        return notification

    def get_for_recipient(self, recipient_email: str) -> list[Notification]:
        return [n for n in self._sent if n.recipient_email == recipient_email]

    def sent_history(self) -> list[Notification]:
        return list(self._sent)
