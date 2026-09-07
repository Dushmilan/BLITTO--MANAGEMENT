"""Prosecution extended module-interface tests (local adapter)."""

import pytest

from app.modules.document_vault.local import LocalDocumentVaultModule
from app.modules.docketing.local import LocalDocketingModule
from app.modules.prosecution.local import LocalProsecutionModule
from app.modules.prosecution.models import OfficeActionKind


def _module():
    vault = LocalDocumentVaultModule()
    return LocalProsecutionModule(docketing=LocalDocketingModule(), document_vault=vault)


def test_file_response_success() -> None:
    module = _module()
    action = module.receive_office_action("app-1", OfficeActionKind.REJECTION, "fix claim 1")
    response = module.file_response("app-1", action.id, "amended claim 1")
    assert response.office_action_id == action.id
    assert response.body == "amended claim 1"


def test_file_response_to_nonexistent_action_raises() -> None:
    module = _module()
    with pytest.raises(ValueError):
        module.file_response("app-1", "ghost-action", "response body")


def test_multiple_office_actions_on_same_application() -> None:
    module = _module()
    a1 = module.receive_office_action("app-1", OfficeActionKind.REJECTION, "fix claim 1")
    a2 = module.receive_office_action("app-1", OfficeActionKind.ALLOWANCE, "allowance")
    assert a1.id != a2.id
    assert a1.application_id == "app-1"
    assert a2.application_id == "app-1"


def test_office_action_from_all_kinds() -> None:
    module = _module()
    for kind in OfficeActionKind:
        action = module.receive_office_action("app-1", kind, f"body for {kind.value}")
        assert action.kind == kind


def test_receive_office_action_sets_application_id() -> None:
    module = _module()
    action = module.receive_office_action("app-42", OfficeActionKind.OBJECTION, "objection body")
    assert action.application_id == "app-42"


def test_file_response_updates_nonexistent_app_with_valid_action() -> None:
    module = _module()
    action = module.receive_office_action("app-1", OfficeActionKind.REJECTION, "fix it")
    response = module.file_response("different-app", action.id, "response")
    assert response.office_action_id == action.id
