"""IDOR (Insecure Direct Object Reference) security tests.

Tests that a user cannot access another user's data through ID manipulation.
"""

from app.domain.common import Role
from app.modules.application_intake.models import Disclosure, Inventor
from app.modules.authorization.models import LoginRequest, RegisterRequest


def _setup_isolation(client):
    """Create two independent users with their own applications."""
    auth = client.app.state.authorization
    intake = client.app.state.application_intake

    if not any(u.email == "idor-md@pdn.ac.lk" for u in auth._users.values()):
        auth.register(RegisterRequest(email="idor-md@pdn.ac.lk", role=Role.MD, password="pw"))
    # Inventor A
    if not any(u.email == "idor-a@pdn.ac.lk" for u in auth._users.values()):
        auth.register(RegisterRequest(email="idor-a@pdn.ac.lk", role=Role.USER, password="pw"))
    resp_a = auth.login(LoginRequest(email="idor-a@pdn.ac.lk", password="pw"))
    app_a = intake.create_application_shell(
        Disclosure(
            title="A's Patent",
            inventor_name="Alice",
            inventor_email="idor-a@pdn.ac.lk",
            summary="A's work",
        )
    )

    # Inventor B
    if not any(u.email == "idor-b@pdn.ac.lk" for u in auth._users.values()):
        auth.register(RegisterRequest(email="idor-b@pdn.ac.lk", role=Role.USER, password="pw"))
    resp_b = auth.login(LoginRequest(email="idor-b@pdn.ac.lk", password="pw"))
    app_b = intake.create_application_shell(
        Disclosure(
            title="B's Patent",
            inventor_name="Bob",
            inventor_email="idor-b@pdn.ac.lk",
            summary="B's work",
        )
    )

    headers_a = {"Authorization": f"Bearer {resp_a.access_token}"}
    headers_b = {"Authorization": f"Bearer {resp_b.access_token}"}

    admin = next(u for u in auth._users.values() if u.role == Role.MD)
    assert admin.email == "idor-md@pdn.ac.lk"
    md_token = auth.issue_token(admin.email)
    md_headers = {"Authorization": f"Bearer {md_token.access_token}"}

    return app_a, app_b, headers_a, headers_b, md_headers


def test_inventor_a_cannot_see_inventor_b_applications(client):
    _, app_b, headers_a, _, _ = _setup_isolation(client)
    resp = client.get("/api/applications", headers=headers_a)
    assert resp.status_code == 200
    ids = [a["id"] for a in resp.json()]
    assert app_b.id not in ids, "Inventor A saw Inventor B's application"


def test_inventor_b_cannot_see_inventor_a_applications(client):
    app_a, _, _, headers_b, _ = _setup_isolation(client)
    resp = client.get("/api/applications", headers=headers_b)
    assert resp.status_code == 200
    ids = [a["id"] for a in resp.json()]
    assert app_a.id not in ids, "Inventor B saw Inventor A's application"


def test_admin_can_see_all_applications(client):
    app_a, app_b, _, _, md_headers = _setup_isolation(client)
    resp = client.get("/api/applications", headers=md_headers)
    assert resp.status_code == 200
    ids = [a["id"] for a in resp.json()]
    assert app_a.id in ids
    assert app_b.id in ids


def test_inventor_cannot_list_other_app_documents(client):
    app_a, _, _, headers_b, _ = _setup_isolation(client)
    resp = client.get(f"/api/applications/{app_a.id}/documents", headers=headers_b)
    assert resp.status_code == 403
