"""Vault security tests — lock/unlock lifecycle, wrong keys, operation blocking."""

import pytest
from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore


def _admin_headers(client):
    resp = client.post("/api/auth/login", json={"email": "admin@blitto.local", "password": "admin1234"})
    assert resp.status_code == 200
    return {"Authorization": f"Bearer {resp.json()['access_token']}"}


def _staff_headers(client):
    resp = client.post("/api/auth/login", json={"email": "admin@blitto.local", "password": "admin1234"})
    return {"Authorization": f"Bearer {resp.json()['access_token']}"}


def test_vault_blocks_upload_when_locked(client):
    headers = _admin_headers(client)
    apps_resp = client.get("/api/applications", headers=headers)
    app_id = apps_resp.json()[0]["id"]

    client.post("/api/vault/lock", headers=headers)

    upload_resp = client.post(
        f"/api/applications/{app_id}/documents?filename=test.pdf",
        headers=headers, content=b"test",
    )
    assert upload_resp.status_code == 423


def test_vault_blocks_delete_when_locked(client):
    headers = _admin_headers(client)
    apps_resp = client.get("/api/applications", headers=headers)
    app_id = apps_resp.json()[0]["id"]

    # Upload first
    client.post("/api/vault/lock", headers=headers)
    client.post("/api/vault/unlock", json={"master_key": "dummy-key"}, headers=headers)
    upload = client.post(
        f"/api/applications/{app_id}/documents?filename=test.pdf",
        headers=headers, content=b"test",
    )
    doc_id = upload.json()["id"]

    # Lock and try to delete
    client.post("/api/vault/lock", headers=headers)
    delete_resp = client.delete(
        f"/api/applications/{app_id}/documents/{doc_id}",
        headers=headers,
    )
    assert delete_resp.status_code == 423


def test_vault_blocks_download_when_locked(client):
    headers = _admin_headers(client)
    apps_resp = client.get("/api/applications", headers=headers)
    app_id = apps_resp.json()[0]["id"]

    client.post("/api/vault/lock", headers=headers)
    client.post("/api/vault/unlock", json={"master_key": "dummy-key"}, headers=headers)
    upload = client.post(
        f"/api/applications/{app_id}/documents?filename=test.pdf",
        headers=headers, content=b"test",
    )
    doc_id = upload.json()["id"]

    client.post("/api/vault/lock", headers=headers)
    download_resp = client.get(
        f"/api/applications/{app_id}/documents/{doc_id}/download",
        headers=headers,
    )
    assert download_resp.status_code == 423


def test_vault_unlock_and_lock_cycle(client):
    headers = _admin_headers(client)

    client.post("/api/vault/lock", headers=headers)
    status = client.get("/api/vault/status", headers=headers).json()
    assert status["locked"] is True

    unlock_resp = client.post(
        "/api/vault/unlock", json={"master_key": "dummy-key"}, headers=headers,
    )
    assert unlock_resp.status_code == 200

    status = client.get("/api/vault/status", headers=headers).json()
    assert status["locked"] is False


def test_pki_store_rejects_wrong_key(tmp_path):
    store = PKIEncryptedStore(
        cert_dir=str(tmp_path / "certs"),
        storage_dir=str(tmp_path / "docs"),
    )
    assert store.unlock("wrong-key-that-is-way-too-long-for-padding=" * 3) is False
    assert store.is_unlocked() is False


def test_pki_locked_store_raises_on_operations(tmp_path):
    store = PKIEncryptedStore(
        cert_dir=str(tmp_path / "certs"),
        storage_dir=str(tmp_path / "docs"),
    )
    with pytest.raises(PermissionError, match="locked"):
        store.put(b"test")
    with pytest.raises(PermissionError, match="locked"):
        store.get("any-ref")
    with pytest.raises(PermissionError, match="locked"):
        store.delete("any-ref")
