"""Prosecution module-interface tests (local adapter)."""

from datetime import datetime

from app.modules.docketing.local import LocalDocketingModule
from app.modules.document_vault.local import LocalDocumentVaultModule
from app.modules.prosecution.local import LocalProsecutionModule
from app.modules.prosecution.models import OfficeActionKind


def _module():
    docketing = LocalDocketingModule()
    vault = LocalDocumentVaultModule()
    return LocalProsecutionModule(docketing=docketing, document_vault=vault), docketing


def test_receive_office_action_dockets_response_deadline() -> None:
    module, docketing = _module()
    action = module.receive_office_action("app-1", OfficeActionKind.REJECTION, "fix it")
    assert action.kind == OfficeActionKind.REJECTION
    # A RESPONSE deadline is docketed for non-allowance actions.
    assert len(docketing.list_deadlines("app-1")) == 1


def test_allowance_dockets_no_response_deadline() -> None:
    module, docketing = _module()
    module.receive_office_action("app-1", OfficeActionKind.ALLOWANCE, "granted")
    assert docketing.list_deadlines("app-1") == []


def test_file_response_validates_action_exists() -> None:
    module, _ = _module()
    try:
        module.file_response("app-1", "ghost-id", "here")
        assert False, "expected ValueError"
    except ValueError:
        pass
