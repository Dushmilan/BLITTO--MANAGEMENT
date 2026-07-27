"""IDOR (Insecure Direct Object Reference) security tests.

Tests that a user cannot access another user's data through ID manipulation.
"""

from app.domain.common import Role
from app.modules.application_intake.models import Disclosure, Inventor
from app.modules.authorization.models import LoginRequest, RegisterRequest


def _setup_isolation(client):
    """Create two independent inventors with their own applications."""
    auth = client.app.state.authorization
    intake = client.app.state.application_intake

    # Inventor A
    if not any(u.email == "idor-a@uni.edu" for u in auth._users.values()):
        auth.register(RegisterRequest(email="idor-a@uni.edu", role=Role.INVENTOR, password="pw"))
    resp_a = auth.login(LoginRequest(email="idor-a@uni.edu", password="pw"))
    app_a = intake.create_application_shell(
        Disclosure(
            title="A's Patent",
            inventors=[Inventor(inventor_name="Alice", inventor_email="idor-a@uni.edu")],
            summary="A's work",
        )
    )

    # Inventor B
    if not any(u.email == "idor-b@uni.edu" for u in auth._users.values()):
        auth.register(RegisterRequest(email="idor-b@uni.edu", role=Role.INVENTOR, password="pw"))
    resp_b = auth.login(LoginRequest(email="idor-b@uni.edu", password="pw"))
    app_b = intake.create_application_shell(
        Disclosure(
            title="B's Patent",
            inventors=[Inventor(inventor_name="Bob", inventor_email="idor-b@uni.edu")],
            summary="B's work",
        )
    )

    headers_a = {"Authorization": f"Bearer {resp_a.access_token}"}
    headers_b = {"Authorization": f"Bearer {resp_b.access_token}"}

    admin = next(u for u in auth._users.values() if u.role == Role.ADMIN)
    admin_token = auth.issue_token(admin.email)
    admin_headers = {"Authorization": f"Bearer {admin_token.access_token}"}

    return app_a, app_b, headers_a, headers_b, admin_headers


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
    app_a, app_b, _, _, admin_headers = _setup_isolation(client)
    resp = client.get("/api/applications", headers=admin_headers)
    assert resp.status_code == 200
    ids = [a["id"] for a in resp.json()]
    assert app_a.id in ids
    assert app_b.id in ids


def test_inventor_cannot_list_other_app_documents(client):
    app_a, _, _, headers_b, _ = _setup_isolation(client)
    resp = client.get(f"/api/applications/{app_a.id}/documents", headers=headers_b)
    assert resp.status_code == 403
