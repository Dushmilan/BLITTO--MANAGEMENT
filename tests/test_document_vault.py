"""Document Vault module-interface tests (local + PKI adapters)."""

from __future__ import annotations

import pytest

from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
from app.adapters.document_storage.local import LocalDocumentStore
from app.modules.document_vault.local import LocalDocumentVaultModule


# ---- Local (in-memory) adapter tests ----


def test_store_retrieve_roundtrip() -> None:
    module = LocalDocumentVaultModule(store=LocalDocumentStore())
    doc = module.store("app-1", "spec.pdf", b"%PDF-1.4 content", "admin@blitto.edu")
    assert doc.filename == "spec.pdf"
    assert module.retrieve(doc.id) == b"%PDF-1.4 content"
    assert len(module.list_for_application("app-1")) == 1


def test_retrieve_unknown_returns_none() -> None:
    module = LocalDocumentVaultModule(store=LocalDocumentStore())
    assert module.retrieve("missing") is None


# ---- PKI encrypted adapter tests ----


@pytest.fixture()
def pki_store(tmp_path) -> PKIEncryptedStore:
    return PKIEncryptedStore(
        cert_dir=str(tmp_path / "certs"),
        storage_dir=str(tmp_path / "docs"),
    )


def test_pki_encrypt_decrypt_roundtrip(pki_store: PKIEncryptedStore) -> None:
    plaintext = b"%PDF-1.4 document content"
    ref = pki_store.put(plaintext)
    assert pki_store.get(ref) == plaintext


def test_pki_empty_content(pki_store: PKIEncryptedStore) -> None:
    ref = pki_store.put(b"")
    assert pki_store.get(ref) == b""


def test_pki_large_content(pki_store: PKIEncryptedStore) -> None:
    large = b"x" * (1024 * 1024)  # 1 MB
    ref = pki_store.put(large)
    assert pki_store.get(ref) == large


def test_pki_unique_encryption(pki_store: PKIEncryptedStore) -> None:
    plaintext = b"same content"
    ref1 = pki_store.put(plaintext)
    ref2 = pki_store.put(plaintext)
    assert ref1 != ref2
    assert pki_store.get(ref1) == plaintext
    assert pki_store.get(ref2) == plaintext


def test_pki_missing_ref_returns_none(pki_store: PKIEncryptedStore) -> None:
    assert pki_store.get("nonexistent.enc") is None


def test_pki_corrupted_blob_returns_none(pki_store: PKIEncryptedStore) -> None:
    ref = pki_store.put(b"secret data")
    blob_path = pki_store._storage_dir / ref
    blob_path.write_bytes(b"corrupted" * 100)
    assert pki_store.get(ref) is None


def test_pki_key_pair_persists(tmp_path) -> None:
    cert_dir = str(tmp_path / "certs")
    d1 = str(tmp_path / "d1")
    store1 = PKIEncryptedStore(cert_dir=cert_dir, storage_dir=d1)
    ref = store1.put(b"before restart")
    store2 = PKIEncryptedStore(cert_dir=cert_dir, storage_dir=d1)
    assert store2.get(ref) == b"before restart"


def test_pki_module_roundtrip(pki_store: PKIEncryptedStore) -> None:
    module = LocalDocumentVaultModule(store=pki_store)
    doc = module.store("app-2", "claim.pdf", b"claim content", "admin@blitto.edu")
    assert doc.filename == "claim.pdf"
    assert module.retrieve(doc.id) == b"claim content"
    assert len(module.list_for_application("app-2")) == 1
