"""HTTP route integration tests for Document Vault endpoints."""

from __future__ import annotations

import pytest
from fastapi.testclient import TestClient

from app.main import app


@pytest.fixture()
def client():
    with TestClient(app) as c:
        yield c


def _admin_token(client: TestClient) -> str:
    resp = client.post("/api/auth/login", json={"email": "admin@blitto.local", "password": "admin1234"})
    assert resp.status_code == 200, resp.text
    return resp.json()["access_token"]


def _auth_header(token: str) -> dict[str, str]:
    return {"Authorization": f"Bearer {token}"}


def test_upload_and_download_roundtrip(client: TestClient) -> None:
    token = _admin_token(client)
    headers = _auth_header(token)

    # List applications to get one.
    apps_resp = client.get("/api/applications", headers=headers)
    assert apps_resp.status_code == 200
    apps = apps_resp.json()
    assert len(apps) > 0
    app_id = apps[0]["id"]

    # Upload a document.
    content = b"%PDF-1.4 sample document content"
    upload_resp = client.post(
        f"/api/applications/{app_id}/documents?filename=test.pdf",
        headers=headers,
        content=content,
    )
    assert upload_resp.status_code == 200, upload_resp.text
    doc = upload_resp.json()
    assert doc["filename"] == "test.pdf"
    assert doc["file_size"] == len(content)
    doc_id = doc["id"]

    # Download the document.
    download_resp = client.get(
        f"/api/applications/{app_id}/documents/{doc_id}/download",
        headers=headers,
    )
    assert download_resp.status_code == 200
    assert download_resp.content == content
    assert "attachment" in download_resp.headers["content-disposition"]


def test_upload_to_nonexistent_application_returns_404(client: TestClient) -> None:
    token = _admin_token(client)
    resp = client.post(
        "/api/applications/nonexistent-id/documents?filename=test.pdf",
        headers=_auth_header(token),
        content=b"content",
    )
    assert resp.status_code == 404
    assert "Application not found" in resp.text


def test_upload_disallowed_extension_returns_422(client: TestClient) -> None:
    token = _admin_token(client)
    headers = _auth_header(token)
    apps_resp = client.get("/api/applications", headers=headers)
    app_id = apps_resp.json()[0]["id"]

    resp = client.post(
        f"/api/applications/{app_id}/documents?filename=evil.exe",
        headers=headers,
        content=b"malicious",
    )
    assert resp.status_code == 422
    assert "not allowed" in resp.text


def test_upload_exceeds_size_limit_returns_413(client: TestClient) -> None:
    token = _admin_token(client)
    headers = _auth_header(token)
    apps_resp = client.get("/api/applications", headers=headers)
    app_id = apps_resp.json()[0]["id"]

    large = b"x" * (10 * 1024 * 1024 + 1)
    resp = client.post(
        f"/api/applications/{app_id}/documents?filename=large.pdf",
        headers=headers,
        content=large,
    )
    assert resp.status_code == 413


def test_delete_document_removes_it(client: TestClient) -> None:
    token = _admin_token(client)
    headers = _auth_header(token)
    apps_resp = client.get("/api/applications", headers=headers)
    app_id = apps_resp.json()[0]["id"]

    # Upload.
    upload_resp = client.post(
        f"/api/applications/{app_id}/documents?filename=delete_me.pdf",
        headers=headers,
        content=b"to be deleted",
    )
    doc_id = upload_resp.json()["id"]

    # Delete.
    delete_resp = client.delete(
        f"/api/applications/{app_id}/documents/{doc_id}",
        headers=headers,
    )
    assert delete_resp.status_code == 204

    # Verify gone from list.
    list_resp = client.get(f"/api/applications/{app_id}/documents", headers=headers)
    assert all(d["id"] != doc_id for d in list_resp.json())


def test_delete_nonexistent_document_returns_404(client: TestClient) -> None:
    token = _admin_token(client)
    resp = client.delete(
        "/api/applications/some-app/documents/nonexistent-id",
        headers=_auth_header(token),
    )
    assert resp.status_code == 404


def test_paralegal_can_upload_and_delete(client: TestClient) -> None:
    """Paralegal role has the same upload/delete permissions as admin."""
    # Login as admin to register a paralegal, then login as paralegal.
    admin_token = _admin_token(client)
    admin_headers = _auth_header(admin_token)

    # Register a paralegal user.
    reg_resp = client.post(
        "/api/auth/register",
        headers=admin_headers,
        json={"email": "paralegal@blitto.local", "role": "paralegal", "password": "paralegal123"},
    )
    assert reg_resp.status_code == 200, reg_resp.text

    paralegal_login = client.post(
        "/api/auth/login",
        json={"email": "paralegal@blitto.local", "password": "paralegal123"},
    )
    para_token = paralegal_login.json()["access_token"]
    para_headers = _auth_header(para_token)

    apps_resp = client.get("/api/applications", headers=para_headers)
    app_id = apps_resp.json()[0]["id"]

    # Upload.
    upload_resp = client.post(
        f"/api/applications/{app_id}/documents?filename=para_test.pdf",
        headers=para_headers,
        content=b"paralegal upload",
    )
    assert upload_resp.status_code == 200
    doc_id = upload_resp.json()["id"]

    # Delete.
    delete_resp = client.delete(
        f"/api/applications/{app_id}/documents/{doc_id}",
        headers=para_headers,
    )
    assert delete_resp.status_code == 204


def test_inventor_cannot_upload(client: TestClient) -> None:
    apps_resp = client.get(
        "/api/applications",
        headers=_auth_header(_admin_token(client)),
    )
    app_id = apps_resp.json()[0]["id"]

    inventor_login = client.post(
        "/api/auth/login",
        json={"email": "inventor1@peradeniya.lk", "password": "password"},
    )
    inv_token = inventor_login.json()["access_token"]

    resp = client.post(
        f"/api/applications/{app_id}/documents?filename=test.pdf",
        headers=_auth_header(inv_token),
        content=b"inventor upload attempt",
    )
    assert resp.status_code == 403


# ---- Vault unlock/lock/status HTTP tests ----


def test_vault_status_returns_locked_state(client: TestClient) -> None:
    token = _admin_token(client)
    resp = client.get("/api/vault/status", headers=_auth_header(token))
    assert resp.status_code == 200
    data = resp.json()
    assert "locked" in data
    assert "remaining_seconds" in data


def test_vault_unlock_and_lock_cycle(client: TestClient) -> None:
    token = _admin_token(client)
    headers = _auth_header(token)

    # Status should show unlocked (LocalDocumentStore is always unlocked).
    status = client.get("/api/vault/status", headers=headers).json()
    assert not status["locked"]

    # Lock it.
    lock_resp = client.post("/api/vault/lock", headers=headers)
    assert lock_resp.status_code == 200

    status = client.get("/api/vault/status", headers=headers).json()
    assert status["locked"]

    # Unlock with a dummy key (local store accepts any key).
    unlock_resp = client.post(
        "/api/vault/unlock",
        headers=headers,
        json={"master_key": "dummy-key"},
    )
    assert unlock_resp.status_code == 200

    status = client.get("/api/vault/status", headers=headers).json()
    assert not status["locked"]


def test_vault_lock_blocks_document_upload(client: TestClient) -> None:
    token = _admin_token(client)
    headers = _auth_header(token)

    apps_resp = client.get("/api/applications", headers=headers)
    app_id = apps_resp.json()[0]["id"]

    # Lock vault.
    client.post("/api/vault/lock", headers=headers)

    # Upload should be blocked.
    upload_resp = client.post(
        f"/api/applications/{app_id}/documents?filename=test.pdf",
        headers=headers,
        content=b"test",
    )
    assert upload_resp.status_code == 423
    assert "locked" in upload_resp.text.lower()
