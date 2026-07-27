"""Prosecution module-interface tests (local adapter)."""

from app.modules.document_vault.local import LocalDocumentVaultModule
from app.modules.prosecution.local import LocalProsecutionModule
from app.modules.prosecution.models import OfficeActionKind


def _module():
    vault = LocalDocumentVaultModule()
    return LocalProsecutionModule(document_vault=vault)


def test_receive_office_action_creates_action() -> None:
    module = _module()
    action = module.receive_office_action("app-1", OfficeActionKind.REJECTION, "fix it")
    assert action.kind == OfficeActionKind.REJECTION


def test_allowance_action_creates_action() -> None:
    module = _module()
    action = module.receive_office_action("app-1", OfficeActionKind.ALLOWANCE, "granted")
    assert action.kind == OfficeActionKind.ALLOWANCE


def test_file_response_validates_action_exists() -> None:
    module = _module()
    try:
        module.file_response("app-1", "ghost-id", "here")
        assert False, "expected ValueError"
    except ValueError:
        pass
