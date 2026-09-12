"""Vault security tests — lock/unlock lifecycle, wrong keys, operation blocking."""

import pytest
from fastapi.testclient import TestClient

from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
from app.domain.common import Role
from app.modules.authorization.models import RegisterRequest


@pytest.fixture(autouse=True)
def _isolated_cwd(tmp_path, monkeypatch) -> None:
    """PKIEncryptedStore persists the master key to ./.env — keep that in tmp."""
    monkeypatch.chdir(tmp_path)

MD_EMAIL = "vault-md@pdn.ac.lk"
MD_PW = "vaultmdpw-123"


def _md_headers(client: TestClient):
    auth = client.app.state.authorization
    if not any(u.email == MD_EMAIL for u in auth._users.values()):
        auth.register(RegisterRequest(email=MD_EMAIL, role=Role.MD, password=MD_PW))
    resp = client.post("/api/auth/login", json={"email": MD_EMAIL, "password": MD_PW})
    assert resp.status_code == 200, resp.text[:300]
    return {"Authorization": f"Bearer {resp.json()['access_token']}"}


def _seed_app_id(client: TestClient, headers: dict) -> str:
    resp = client.post(
        "/api/applications",
        json={
            "inventor_name": "Vault Tester",
            "inventor_email": "vault-user@pdn.ac.lk",
            "title": "Vault Widget",
            "summary": "s",
        },
        headers=headers,
    )
    assert resp.status_code == 200, resp.text[:300]
    return resp.json()["id"]


def test_vault_blocks_upload_when_locked(client):
    headers = _md_headers(client)
    app_id = _seed_app_id(client, headers)

    client.post("/api/vault/lock", headers=headers)

    upload_resp = client.post(
        f"/api/applications/{app_id}/documents?filename=test.pdf",
        headers=headers, content=b"test",
    )
    assert upload_resp.status_code == 423


def test_vault_blocks_delete_when_locked(client):
    headers = _md_headers(client)
    app_id = _seed_app_id(client, headers)

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
    headers = _md_headers(client)
    app_id = _seed_app_id(client, headers)

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
    headers = _md_headers(client)

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
    ref = store.put(b"test")  # encrypt allowed while locked
    with pytest.raises(PermissionError, match="locked"):
        store.get(ref)
    with pytest.raises(PermissionError, match="locked"):
        store.delete(ref)
