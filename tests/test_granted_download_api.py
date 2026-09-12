"""TDD RED: GRANTED download with timestamp, grant warnings, request-doc flow (API level)."""

from fastapi.testclient import TestClient

from app.domain.common import Role
from app.main import app
from app.modules.application_intake.models import Disclosure
from app.modules.authorization.models import RegisterRequest


def _setup_client():
    client = TestClient(app)
    client.__enter__()
    try:
        auth = app.state.authorization
        auth.register(
            RegisterRequest(email="md@pdn.ac.lk", role=Role.MD, password="mdpw")
        )
        auth.register(
            RegisterRequest(email="inv@pdn.ac.lk", role=Role.USER, password="invpw")
        )
        admin_tok = client.post(
            "/auth/login",
            json={"email": "md@pdn.ac.lk", "password": "mdpw"},
        ).json()["access_token"]
        inv_tok = client.post(
            "/auth/login", json={"email": "inv@pdn.ac.lk", "password": "invpw"}
        ).json()["access_token"]
        intake = app.state.application_intake
        application = intake.create_application_shell(
            Disclosure(
                inventor_name="Inv",
                inventor_email="inv@pdn.ac.lk",
                title="Widget",
                summary="s",
            )
        )
        return client, application.id, admin_tok, inv_tok
    except Exception:
        client.__exit__(None, None, None)
        raise


def _teardown(client):
    client.__exit__(None, None, None)


def _auth(tok):
    return {"Authorization": f"Bearer {tok}"}


def _grant(client, app_id, tok):
    """Walk the legal lifecycle to GRANTED (DRAFT->FILED->ACK->EXAM->GRANTED)."""
    last = None
    for status in ("FILED", "ACKNOWLEDGED", "EXAMINATION", "GRANTED"):
        last = client.post(
            f"/applications/{app_id}/status",
            params={"new_status": status},
            headers=_auth(tok),
        )
        assert last.status_code == 200, last.text[:300]
    return last


def test_grant_without_document_returns_warning_and_missing_list() -> None:
    client, app_id, admin_tok, _ = _setup_client()
    try:
        resp = _grant(client, app_id, admin_tok)
        assert resp.status_code == 200
        assert "warning" in resp.json(), resp.json()

        missing = client.get(
            "/applications/missing-documents", headers=_auth(admin_tok)
        )
        assert missing.status_code == 200
        assert any(a["id"] == app_id for a in missing.json())
    finally:
        _teardown(client)


def test_user_download_granted_with_timestamp_and_audit() -> None:
    client, app_id, admin_tok, inv_tok = _setup_client()
    try:
        client.post(
            f"/applications/{app_id}/documents",
            params={"filename": "grant.pdf"},
            content=b"%PDF-1.4 granted",
            headers={**_auth(admin_tok), "Content-Type": "application/pdf"},
        )
        _grant(client, app_id, admin_tok)
        resp = client.get(
            f"/applications/{app_id}/download", headers=_auth(inv_tok)
        )
        assert resp.status_code == 200, resp.text[:300]
        assert resp.content == b"%PDF-1.4 granted"
        assert "attachment" in resp.headers.get("content-disposition", "")
        assert resp.headers.get("x-downloaded-at"), "timestamp header missing"
        audits = app.state.audit.for_application(app_id)
        assert any(a.action == "download_granted_patent" for a in audits)
    finally:
        _teardown(client)


def test_user_download_granted_without_doc_gives_pending() -> None:
    client, app_id, admin_tok, inv_tok = _setup_client()
    try:
        _grant(client, app_id, admin_tok)
        resp = client.get(
            f"/applications/{app_id}/download", headers=_auth(inv_tok)
        )
        assert resp.status_code == 409
        assert resp.json()["detail"].get("code") == "document_pending"
    finally:
        _teardown(client)


def test_user_can_request_document_when_pending() -> None:
    client, app_id, admin_tok, inv_tok = _setup_client()
    try:
        _grant(client, app_id, admin_tok)
        resp = client.post(
            f"/applications/{app_id}/request-document", headers=_auth(inv_tok)
        )
        assert resp.status_code == 200, resp.text[:300]
        # Second request is rate-limited (one open request).
        again = client.post(
            f"/applications/{app_id}/request-document", headers=_auth(inv_tok)
        )
        assert again.status_code == 429
    finally:
        _teardown(client)


def test_non_granted_download_forbidden_for_user() -> None:
    client, app_id, _, inv_tok = _setup_client()
    try:
        resp = client.get(
            f"/applications/{app_id}/download", headers=_auth(inv_tok)
        )
        assert resp.status_code == 403
    finally:
        _teardown(client)
