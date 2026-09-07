"""Document Vault module-interface tests (local adapter)."""

from app.adapters.document_storage.local import LocalDocumentStore
from app.modules.document_vault.local import LocalDocumentVaultModule


def test_store_retrieve_roundtrip() -> None:
    module = LocalDocumentVaultModule(store=LocalDocumentStore())
    doc = module.store("app-1", "spec.pdf", b"%PDF-1.4 content", "md@pdn.ac.lk")
    assert doc.filename == "spec.pdf"
    assert module.retrieve(doc.id) == b"%PDF-1.4 content"
    assert len(module.list_for_application("app-1")) == 1


def test_retrieve_unknown_returns_none() -> None:
    module = LocalDocumentVaultModule(store=LocalDocumentStore())
    assert module.retrieve("missing") is None
