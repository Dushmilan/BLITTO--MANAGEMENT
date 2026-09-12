"""Document Vault module-interface tests (local + PKI adapters)."""

from __future__ import annotations

import re

import pytest

from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
from app.adapters.document_storage.local import LocalDocumentStore
from app.modules.document_vault.local import LocalDocumentVaultModule


# ---- Local (in-memory) adapter tests ----


def test_store_retrieve_roundtrip() -> None:
    module = LocalDocumentVaultModule(store=LocalDocumentStore())
    doc = module.store("app-1", "spec.pdf", b"%PDF-1.4 content", "md@pdn.ac.lk")
    assert doc.filename == "spec.pdf"
    assert module.retrieve(doc.id) == b"%PDF-1.4 content"
    assert len(module.list_for_application("app-1")) == 1


def test_retrieve_unknown_returns_none() -> None:
    module = LocalDocumentVaultModule(store=LocalDocumentStore())
    assert module.retrieve("missing") is None


# ---- PKI encrypted adapter tests ----


@pytest.fixture(autouse=True)
def _isolated_cwd(tmp_path, monkeypatch) -> None:
    """PKIEncryptedStore persists the master key to ./.env — keep that in tmp."""
    monkeypatch.chdir(tmp_path)


@pytest.fixture()
def pki_store(tmp_path) -> PKIEncryptedStore:
    store = PKIEncryptedStore(
        cert_dir=str(tmp_path / "certs"),
        storage_dir=str(tmp_path / "docs"),
    )
    store.unlock(store.generated_master_key)
    return store


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
    store1.unlock(store1.generated_master_key)
    ref = store1.put(b"before restart")
    store2 = PKIEncryptedStore(cert_dir=cert_dir, storage_dir=d1)
    store2.unlock(store1.generated_master_key)
    assert store2.get(ref) == b"before restart"


def test_pki_module_roundtrip(pki_store: PKIEncryptedStore) -> None:
    module = LocalDocumentVaultModule(store=pki_store)
    doc = module.store("app-2", "claim.pdf", b"claim content", "md@pdn.ac.lk")
    assert doc.filename == "claim.pdf"
    assert module.retrieve(doc.id) == b"claim content"
    assert len(module.list_for_application("app-2")) == 1


# ---- Document storage adapter delete tests ----


def test_local_store_delete_removes_content() -> None:
    store = LocalDocumentStore()
    ref = store.put(b"delete me")
    assert store.get(ref) == b"delete me"
    assert store.delete(ref) is True
    assert store.get(ref) is None


def test_local_store_delete_missing_returns_false() -> None:
    store = LocalDocumentStore()
    assert store.delete("mem:nonexistent") is False


def test_pki_store_delete_removes_content(pki_store: PKIEncryptedStore) -> None:
    ref = pki_store.put(b"delete me")
    assert pki_store.get(ref) == b"delete me"
    assert pki_store.delete(ref) is True
    assert pki_store.get(ref) is None


def test_pki_store_delete_missing_returns_false(pki_store: PKIEncryptedStore) -> None:
    assert pki_store.delete("nonexistent.enc") is False


# ---- Document Vault module delete tests ----


def test_vault_delete_removes_document() -> None:
    module = LocalDocumentVaultModule(store=LocalDocumentStore())
    doc = module.store("app-1", "delete.pdf", b"content", "md@pdn.ac.lk")
    assert len(module.list_for_application("app-1")) == 1
    assert module.delete(doc.id) is True
    assert module.retrieve(doc.id) is None
    assert len(module.list_for_application("app-1")) == 0


def test_vault_delete_unknown_returns_false() -> None:
    module = LocalDocumentVaultModule(store=LocalDocumentStore())
    assert module.delete("missing") is False


# ---- File size tracking tests ----


def test_store_records_file_size() -> None:
    module = LocalDocumentVaultModule(store=LocalDocumentStore())
    content = b"hello world"
    doc = module.store("app-1", "hello.txt", content, "md@pdn.ac.lk")
    assert doc.file_size == len(content)


def test_store_records_file_size_empty() -> None:
    module = LocalDocumentVaultModule(store=LocalDocumentStore())
    doc = module.store("app-1", "empty.txt", b"", "md@pdn.ac.lk")
    assert doc.file_size == 0


# ---- PKI vault unlock/lock tests ----


def test_pki_first_run_generates_master_key(tmp_path) -> None:
    store = PKIEncryptedStore(
        cert_dir=str(tmp_path / "certs"),
        storage_dir=str(tmp_path / "docs"),
    )
    assert isinstance(store.generated_master_key, str)
    assert len(store.generated_master_key) > 20
    assert store.is_unlocked() is False


def test_pki_unlock_with_correct_key(tmp_path) -> None:
    store = PKIEncryptedStore(
        cert_dir=str(tmp_path / "certs"),
        storage_dir=str(tmp_path / "docs"),
    )
    key = store.generated_master_key
    assert store.unlock(key) is True
    assert store.is_unlocked() is True
    assert store.unlock_remaining() > 0


def test_pki_unlock_with_wrong_key_returns_false(tmp_path) -> None:
    store = PKIEncryptedStore(
        cert_dir=str(tmp_path / "certs"),
        storage_dir=str(tmp_path / "docs"),
    )
    assert store.unlock("AAAA" + "=" * 40) is False
    assert store.is_unlocked() is False


def test_pki_lock_clears_unlocked_state(tmp_path) -> None:
    store = PKIEncryptedStore(
        cert_dir=str(tmp_path / "certs"),
        storage_dir=str(tmp_path / "docs"),
    )
    store.unlock(store.generated_master_key)
    assert store.is_unlocked() is True
    store.lock()
    assert store.is_unlocked() is False
    assert store.unlock_remaining() == 0.0


def test_pki_unlock_persists_across_store_recreation(tmp_path) -> None:
    """Same RSA key used across server restarts with same master key."""
    cert_dir = str(tmp_path / "certs2")
    d1 = str(tmp_path / "d1")
    store1 = PKIEncryptedStore(cert_dir=cert_dir, storage_dir=d1)
    master_key = store1.generated_master_key
    store1.unlock(master_key)
    ref = store1.put(b"persistent content")
    store1.lock()

    store2 = PKIEncryptedStore(cert_dir=cert_dir, storage_dir=d1)
    assert store2.generated_master_key is None  # not first run
    assert store2.unlock(master_key) is True
    assert store2.get(ref) == b"persistent content"


def test_pki_put_allowed_while_locked_get_delete_require_unlock(tmp_path) -> None:
    store = PKIEncryptedStore(
        cert_dir=str(tmp_path / "certs"),
        storage_dir=str(tmp_path / "docs"),
    )
    ref = store.put(b"test")  # allowed while locked (public-key encrypt)
    with pytest.raises(PermissionError, match="locked"):
        store.get(ref)
    with pytest.raises(PermissionError, match="locked"):
        store.delete(ref)
    store.unlock(store.generated_master_key)
    assert store.get(ref) == b"test"


def test_pki_does_not_write_env_file(tmp_path, monkeypatch) -> None:
    monkeypatch.chdir(tmp_path)
    (tmp_path / ".env").write_text("EXISTING=1\n", encoding="utf-8")
    from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
    store = PKIEncryptedStore(
        cert_dir=str(tmp_path / "certs"),
        storage_dir=str(tmp_path / "docs"),
    )
    assert store.generated_master_key is not None
    content = (tmp_path / ".env").read_text(encoding="utf-8")
    assert "BLITTO_VAULT_MASTER_KEY" not in content
    assert content.strip() == "EXISTING=1"


def test_pki_init_with_enc_but_missing_pub_does_not_crash(tmp_path, monkeypatch) -> None:
    monkeypatch.chdir(tmp_path)
    from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
    s1 = PKIEncryptedStore(cert_dir=str(tmp_path / "c"), storage_dir=str(tmp_path / "d1"))
    key = s1.generated_master_key
    assert key is not None
    (tmp_path / "c" / "public_key.pem").unlink()
    s2 = PKIEncryptedStore(cert_dir=str(tmp_path / "c"), storage_dir=str(tmp_path / "d2"))
    assert s2.unlock(key) is True


def test_pki_unlock_rejects_wrong_length_key_fast(tmp_path, monkeypatch) -> None:
    monkeypatch.chdir(tmp_path)
    import base64
    from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
    store = PKIEncryptedStore(cert_dir=str(tmp_path / "c"), storage_dir=str(tmp_path / "d"))
    short = base64.b64encode(b"too-short").decode()
    assert store.unlock(short) is False
    assert store.is_unlocked() is False


def test_pki_ref_is_safe_filename(pki_store) -> None:
    ref = pki_store.put(b"hello")
    assert re.fullmatch(r"[0-9a-f-]+\.enc", ref), ref
    assert pki_store.get("../private_key.enc") is None
    assert pki_store.delete("../../etc/passwd.enc") is False


def test_pki_blob_binds_file_id(pki_store) -> None:
    ref = pki_store.put(b"bind me")
    blob = (pki_store._storage_dir / ref).read_bytes()
    assert blob[:2] == b"V1"
    # Copy ref1's whole blob to ref2's path. It is a valid ciphertext for
    # file_id1, but get(ref2) uses file_id2 as AAD -> must fail.
    # Without AAD binding this decrypts successfully (returns b"bind me").
    ref2 = pki_store.put(b"other content here!!")
    (pki_store._storage_dir / ref2).write_bytes(blob)
    assert pki_store.get(ref2) is None


def test_pki_put_while_locked_succeeds_get_requires_unlock(tmp_path, monkeypatch) -> None:
    monkeypatch.chdir(tmp_path)
    from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
    store = PKIEncryptedStore(cert_dir=str(tmp_path / "c"), storage_dir=str(tmp_path / "d"))
    key = store.generated_master_key
    assert store.is_unlocked() is False
    ref = store.put(b"encrypt while locked")
    assert ref.endswith(".enc")
    import pytest
    with pytest.raises(PermissionError, match="locked"):
        store.get(ref)
    assert store.unlock(key) is True
    assert store.get(ref) == b"encrypt while locked"


def test_local_store_unlock_remaining_is_zero_when_unlocked() -> None:
    from app.adapters.document_storage.local import LocalDocumentStore
    store = LocalDocumentStore()
    store.unlock("anything")
    assert store.is_unlocked() is True
    assert store.unlock_remaining() == 0.0


def test_build_vault_selects_pki_when_env_set(tmp_path, monkeypatch) -> None:
    monkeypatch.chdir(tmp_path)
    # `settings` is a cached singleton built at import; patch the object itself.
    from app.core.config import settings
    monkeypatch.setattr(settings, "document_store", "pki")
    monkeypatch.setattr(settings, "vault_cert_dir", str(tmp_path / "certs"))
    monkeypatch.setattr(settings, "vault_storage_dir", str(tmp_path / "docs"))
    from app.main import build_document_vault
    vault = build_document_vault()
    from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
    assert isinstance(vault._store, PKIEncryptedStore)
