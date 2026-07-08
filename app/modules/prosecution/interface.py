"""Prosecution - module interface.

Deep module per Agent.md: `prosecution` (in-process, priority 5).
Depends on `docketing` and `documentVault` (wired in main.py lifespan).
"""

from __future__ import annotations

from typing import Protocol

from app.modules.prosecution.models import OfficeAction, OfficeActionKind, Response


class ProsecutionModule(Protocol):
    def receive_office_action(
        self, application_id: str, kind: OfficeActionKind, body: str
    ) -> OfficeAction:
        ...

    def file_response(
        self, application_id: str, office_action_id: str, body: str
    ) -> Response:
        ...
