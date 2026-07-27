"""Prosecution - local in-memory adapter."""

from __future__ import annotations

import uuid

from app.modules.document_vault.local import LocalDocumentVaultModule
from app.modules.prosecution.interface import ProsecutionModule
from app.modules.prosecution.models import OfficeAction, OfficeActionKind, Response


class LocalProsecutionModule:
    def __init__(
        self,
        document_vault: LocalDocumentVaultModule,
    ) -> None:
        self._document_vault = document_vault
        self._actions: dict[str, OfficeAction] = {}
        self._responses: dict[str, Response] = {}

    def receive_office_action(
        self, application_id: str, kind: OfficeActionKind, body: str
    ) -> OfficeAction:
        action = OfficeAction(
            id=str(uuid.uuid4()),
            application_id=application_id,
            kind=kind,
            body=body,
        )
        self._actions[action.id] = action
        return action

    def file_response(
        self, application_id: str, office_action_id: str, body: str
    ) -> Response:
        if office_action_id not in self._actions:
            raise ValueError(f"unknown office_action_id: {office_action_id}")
        response = Response(
            id=str(uuid.uuid4()),
            application_id=application_id,
            office_action_id=office_action_id,
            body=body,
        )
        self._responses[response.id] = response
        return response



