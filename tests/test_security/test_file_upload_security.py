"""File upload security tests — extension validation, size limits, edge cases."""

import pytest

from app.domain.common import Role
from app.modules.authorization.models import RegisterRequest

MD_EMAIL = "secf-md@pdn.ac.lk"
MD_PW = "secf-mdpw-123"


def _md_token(client):
    auth = client.app.state.authorization
    if not any(u.email == MD_EMAIL for u in auth._users.values()):
        auth.register(RegisterRequest(email=MD_EMAIL, role=Role.MD, password=MD_PW))
    resp = client.post("/api/auth/login", json={"email": MD_EMAIL, "password": MD_PW})
    assert resp.status_code == 200, resp.text[:300]
    return resp.json()["access_token"]


def _app_id(client, headers):
    resp = client.post(
        "/api/applications",
        json={
            "inventor_name": "Upload Tester",
            "inventor_email": "upload-user@pdn.ac.lk",
            "title": "Upload Widget",
            "summary": "s",
        },
        headers=headers,
    )
    assert resp.status_code == 200, resp.text[:300]
    return resp.json()["id"]


DOUBLE_EXTENSION_CASES = [
    pytest.param("exploit.pdf.exe", 422, id="double extension .pdf.exe"),
    pytest.param("document.exe.pdf", 200, id="double extension .exe.pdf (ends in allowed)"),
    pytest.param("safe.pdf", 200, id="single allowed extension"),
    pytest.param("no_extension", 422, id="no extension"),
    pytest.param(".hidden", 422, id="only extension"),
    pytest.param("", 422, id="empty filename"),
]


@pytest.mark.parametrize("filename,expected", DOUBLE_EXTENSION_CASES)
def test_filename_extension_validation(client, filename, expected):
    headers = {"Authorization": f"Bearer {_md_token(client)}"}
    app_id = _app_id(client, headers)
    resp = client.post(
        f"/api/applications/{app_id}/documents?filename={filename}",
        headers=headers, content=b"test content",
    )
    assert resp.status_code == expected, (
        f"filename={filename!r} expected {expected} got {resp.status_code}: {resp.text}"
    )


CASE_VARIATIONS = [
    pytest.param("TEST.PDF", id="uppercase .PDF"),
    pytest.param("Test.Pdf", id="mixed case .Pdf"),
    pytest.param("test.PNG", id="uppercase .PNG"),
]


@pytest.mark.parametrize("filename", CASE_VARIATIONS)
def test_case_insensitive_extension_validation(client, filename):
    headers = {"Authorization": f"Bearer {_md_token(client)}"}
    app_id = _app_id(client, headers)
    resp = client.post(
        f"/api/applications/{app_id}/documents?filename={filename}",
        headers=headers, content=b"test content",
    )
    assert resp.status_code == 200, (
        f"filename={filename!r} expected 200 got {resp.status_code}: {resp.text}"
    )


def test_upload_exceeds_size_limit(client):
    headers = {"Authorization": f"Bearer {_md_token(client)}"}
    app_id = _app_id(client, headers)
    large = b"x" * (10 * 1024 * 1024 + 1)
    resp = client.post(
        f"/api/applications/{app_id}/documents?filename=large.pdf",
        headers=headers, content=large,
    )
    assert resp.status_code == 413


def test_upload_exactly_at_size_limit(client):
    headers = {"Authorization": f"Bearer {_md_token(client)}"}
    app_id = _app_id(client, headers)
    exact = b"x" * (10 * 1024 * 1024)
    resp = client.post(
        f"/api/applications/{app_id}/documents?filename=exact.pdf",
        headers=headers, content=exact,
    )
    assert resp.status_code == 200


def test_empty_file_upload(client):
    headers = {"Authorization": f"Bearer {_md_token(client)}"}
    app_id = _app_id(client, headers)
    resp = client.post(
        f"/api/applications/{app_id}/documents?filename=empty.pdf",
        headers=headers, content=b"",
    )
    assert resp.status_code == 200
    doc = resp.json()
    assert doc["file_size"] == 0


def test_upload_to_nonexistent_application(client):
    headers = {"Authorization": f"Bearer {_md_token(client)}"}
    resp = client.post(
        "/api/applications/nonexistent-id/documents?filename=test.pdf",
        headers=headers, content=b"test",
    )
    assert resp.status_code == 404
