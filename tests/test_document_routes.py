"""HTTP route integration tests for Document Vault endpoints."""

from __future__ import annotations

import pytest
from fastapi.testclient import TestClient

from app.domain.common import Role
from app.main import app
from app.modules.authorization.models import RegisterRequest

MD_EMAIL = "doc-md@pdn.ac.lk"
MD_PW = "docmdpw-123"
DIRECTOR_EMAIL = "doc-director@pdn.ac.lk"
DIRECTOR_PW = "docdirpw-123"
USER_EMAIL = "doc-user@pdn.ac.lk"
USER_PW = "docuserpw-123"


@pytest.fixture()
def client():
    with TestClient(app) as c:
        yield c


def _ensure_users(client: TestClient) -> None:
    auth = client.app.state.authorization
    for email, role, pw in (
        (MD_EMAIL, Role.MD, MD_PW),
        (DIRECTOR_EMAIL, Role.DIRECTOR, DIRECTOR_PW),
        (USER_EMAIL, Role.USER, USER_PW),
    ):
        if not any(u.email == email for u in auth._users.values()):
            auth.register(RegisterRequest(email=email, role=role, password=pw))


def _login(client: TestClient, email: str, password: str) -> str:
    resp = client.post("/api/auth/login", json={"email": email, "password": password})
    assert resp.status_code == 200, resp.text[:300]
    return resp.json()["access_token"]


def _md_token(client: TestClient) -> str:
    _ensure_users(client)
    return _login(client, MD_EMAIL, MD_PW)


def _auth_header(token: str) -> dict[str, str]:
    return {"Authorization": f"Bearer {token}"}


def _app_id(client: TestClient, headers: dict[str, str]) -> str:
    resp = client.post(
        "/api/applications",
        json={
            "inventor_name": "Doc Tester",
            "inventor_email": USER_EMAIL,
            "title": "Doc Widget",
            "summary": "s",
        },
        headers=headers,
    )
    assert resp.status_code == 200, resp.text[:300]
    return resp.json()["id"]


def test_upload_and_download_roundtrip(client: TestClient) -> None:
    token = _md_token(client)
    headers = _auth_header(token)
    app_id = _app_id(client, headers)

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
    token = _md_token(client)
    resp = client.post(
        "/api/applications/nonexistent-id/documents?filename=test.pdf",
        headers=_auth_header(token),
        content=b"content",
    )
    assert resp.status_code == 404
    assert "Unknown application" in resp.text


def test_upload_disallowed_extension_returns_422(client: TestClient) -> None:
    token = _md_token(client)
    headers = _auth_header(token)
    app_id = _app_id(client, headers)

    resp = client.post(
        f"/api/applications/{app_id}/documents?filename=evil.exe",
        headers=headers,
        content=b"malicious",
    )
    assert resp.status_code == 422
    assert "not allowed" in resp.text


def test_upload_exceeds_size_limit_returns_413(client: TestClient) -> None:
    token = _md_token(client)
    headers = _auth_header(token)
    app_id = _app_id(client, headers)

    large = b"x" * (10 * 1024 * 1024 + 1)
    resp = client.post(
        f"/api/applications/{app_id}/documents?filename=large.pdf",
        headers=headers,
        content=large,
    )
    assert resp.status_code == 413


def test_delete_document_removes_it(client: TestClient) -> None:
    token = _md_token(client)
    headers = _auth_header(token)
    app_id = _app_id(client, headers)

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
    token = _md_token(client)
    resp = client.delete(
        "/api/applications/some-app/documents/nonexistent-id",
        headers=_auth_header(token),
    )
    assert resp.status_code == 404


def test_director_can_upload_and_delete(client: TestClient) -> None:
    """Director role has the same upload/delete permissions as MD."""
    _ensure_users(client)
    dir_token = _login(client, DIRECTOR_EMAIL, DIRECTOR_PW)
    dir_headers = _auth_header(dir_token)
    app_id = _app_id(client, dir_headers)

    # Upload.
    upload_resp = client.post(
        f"/api/applications/{app_id}/documents?filename=dir_test.pdf",
        headers=dir_headers,
        content=b"director upload",
    )
    assert upload_resp.status_code == 200
    doc_id = upload_resp.json()["id"]

    # Delete.
    delete_resp = client.delete(
        f"/api/applications/{app_id}/documents/{doc_id}",
        headers=dir_headers,
    )
    assert delete_resp.status_code == 204


def test_user_cannot_upload(client: TestClient) -> None:
    _ensure_users(client)
    app_id = _app_id(client, _auth_header(_md_token(client)))

    user_token = _login(client, USER_EMAIL, USER_PW)
    resp = client.post(
        f"/api/applications/{app_id}/documents?filename=test.pdf",
        headers=_auth_header(user_token),
        content=b"user upload attempt",
    )
    assert resp.status_code == 403


# ---- Vault unlock/lock/status HTTP tests ----


def test_vault_status_returns_locked_state(client: TestClient) -> None:
    token = _md_token(client)
    resp = client.get("/api/vault/status", headers=_auth_header(token))
    assert resp.status_code == 200
    data = resp.json()
    assert "locked" in data
    assert "remaining_seconds" in data


def test_vault_unlock_and_lock_cycle(client: TestClient) -> None:
    token = _md_token(client)
    headers = _auth_header(token)

    # Status should show unlocked (local store starts unlocked).
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
    token = _md_token(client)
    headers = _auth_header(token)
    app_id = _app_id(client, headers)

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
