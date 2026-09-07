"""Notification - local console/mock adapter (true-external seam mock)."""

from __future__ import annotations

import uuid

from app.modules.notification.interface import NotificationModule
from app.modules.notification.models import Notification

# Per-status subject/body designs. GRANTED has two variants depending on
# whether the patent document is already uploaded.
_STATUS_SUBJECTS: dict[str, str] = {
    "DRAFT": "Patent {ref} drafted",
    "FILED": "Patent {ref} filed to NIPO",
    "PUBLISHED": "Patent {ref} published",
    "EXAMINATION": "Patent {ref} under examination",
    "GRANTED": "Patent {ref} GRANTED — download ready",
    "REJECTED": "Patent {ref} update: rejected",
    "MAINTENANCE": "Patent {ref} in maintenance",
}

_STATUS_BODIES: dict[str, str] = {
    "DRAFT": "The patent application {ref} is now: DRAFT.",
    "FILED": "The patent application {ref} has been filed to NIPO. Ref: {ref}.",
    "PUBLISHED": "The patent application {ref} is now published.",
    "EXAMINATION": "The patent application {ref} is now: EXAMINATION. No action needed.",
    "GRANTED": (
        "Good news — patent {ref} is now GRANTED. "
        "You can download the patent document from your BLITTO portal."
    ),
    "REJECTED": (
        "The patent application {ref} was rejected. Please contact BLITTO for next steps."
    ),
    "MAINTENANCE": "The patent {ref} is now in maintenance. Watch for fee deadlines.",
}


class LocalNotificationModule:
    def __init__(self) -> None:
        self._sent: list[Notification] = []

    def send_status_change(
        self,
        recipient_email: str,
        application_ref: str,
        new_status: str,
        document_available: bool = True,
    ) -> Notification:
        status = new_status.upper()
        if status == "GRANTED" and not document_available:
            notification = Notification(
                id=str(uuid.uuid4()),
                recipient_email=recipient_email,
                kind="status_change",
                subject=f"Patent {application_ref} GRANTED — document pending",
                body=(
                    f"Good news — patent {application_ref} is now GRANTED. "
                    "The document is pending upload by BLITTO; "
                    "you can request it from your portal and we will notify you "
                    "when it is ready to download."
                ),
            )
        else:
            notification = Notification(
                id=str(uuid.uuid4()),
                recipient_email=recipient_email,
                kind="status_change",
                subject=_STATUS_SUBJECTS.get(
                    status, f"Patent {application_ref} status changed"
                ).format(ref=application_ref),
                body=_STATUS_BODIES.get(
                    status,
                    f"The patent application {application_ref} is now: {new_status}.",
                ).format(ref=application_ref),
            )
        return self._record(notification)

    def send_admin_warning(self, admin_email: str, application_ref: str) -> Notification:
        return self._record(
            Notification(
                id=str(uuid.uuid4()),
                recipient_email=admin_email,
                kind="grant_without_document",
                subject=f"Action needed: GRANTED {application_ref} has no document",
                body=(
                    f"Patent {application_ref} was marked GRANTED with no patent "
                    "document uploaded. Please upload the granted patent document "
                    "so the inventor can download it."
                ),
            )
        )

    def send_document_request(
        self, admin_email: str, application_ref: str, inventor_email: str
    ) -> Notification:
        return self._record(
            Notification(
                id=str(uuid.uuid4()),
                recipient_email=admin_email,
                kind="document_request",
                subject=f"Inventor requests document for {application_ref}",
                body=(
                    f"Inventor {inventor_email} requests the granted patent "
                    f"document for {application_ref}. Please upload it."
                ),
            )
        )

    def send_download_ready(
        self, recipient_email: str, application_ref: str
    ) -> Notification:
        return self._record(
            Notification(
                id=str(uuid.uuid4()),
                recipient_email=recipient_email,
                kind="download_ready",
                subject=f"Patent {application_ref} document now available",
                body=(
                    f"The patent document for {application_ref} is now available. "
                    "You can download it from your BLITTO portal."
                ),
            )
        )

    def sent_history(self) -> list[Notification]:
        return list(self._sent)

    def _record(self, notification: Notification) -> Notification:
        self._sent.append(notification)
        # Local mock: print instead of sending.
        print(f"[notification:mock] -> {notification.recipient_email}: {notification.subject}")
        return notification
