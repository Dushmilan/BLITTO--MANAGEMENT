"""Notification - module interface.

Deep module per Agent.md: `notification` (true-external, priority 6).
Production uses an email provider seam; local uses a console/mock adapter.
"""

from __future__ import annotations

from typing import Protocol

from app.modules.notification.models import Notification


class NotificationModule(Protocol):
    def send_status_change(
        self, recipient_email: str, application_ref: str, new_status: str
    ) -> Notification:
        ...
