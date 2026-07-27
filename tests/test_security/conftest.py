"""Shared fixtures for security tests."""

from __future__ import annotations

import sys
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

ROOT = Path(__file__).resolve().parent.parent.parent
sys.path.insert(0, str(ROOT))

from app.main import app  # noqa: E402
from app.domain.common import Role  # noqa: E402
from app.modules.authorization.models import (  # noqa: E402
    LoginRequest, RegisterRequest,
)


@pytest.fixture
def client():
    with TestClient(app) as c:
        yield c


def _register_and_login(client, email: str, role: Role, password: str = "pw"):
    auth = client.app.state.authorization
    if not any(u.email == email for u in auth._users.values()):
        auth.register(RegisterRequest(email=email, role=role, password=password))
    token = auth.login(LoginRequest(email=email, password=password))
    assert token is not None, f"failed to login {email}"
    return token.access_token


@pytest.fixture
def admin_token(client):
    return _register_and_login(client, "sec-admin@blitto.edu", Role.ADMIN)


@pytest.fixture
def attorney_token(client):
    return _register_and_login(client, "sec-attorney@blitto.edu", Role.ATTORNEY)


@pytest.fixture
def paralegal_token(client):
    return _register_and_login(client, "sec-paralegal@blitto.edu", Role.PARALEGAL)


@pytest.fixture
def inventor_token(client):
    return _register_and_login(client, "sec-inventor@uni.edu", Role.INVENTOR)


@pytest.fixture
def auth_headers(admin_token, attorney_token, paralegal_token, inventor_token):
    return {
        Role.ADMIN: {"Authorization": f"Bearer {admin_token}"},
        Role.ATTORNEY: {"Authorization": f"Bearer {attorney_token}"},
        Role.PARALEGAL: {"Authorization": f"Bearer {paralegal_token}"},
        Role.INVENTOR: {"Authorization": f"Bearer {inventor_token}"},
    }


@pytest.fixture
def seed_app_id(client, admin_token):
    resp = client.post(
        "/api/applications",
        json={
            "title": "Security Test Patent",
            "inventors": [{"inventor_name": "Sec Tester", "inventor_email": "sec-inventor@uni.edu"}],
            "summary": "RBAC test",
        },
        headers={"Authorization": f"Bearer {admin_token}"},
    )
    assert resp.status_code == 200, resp.text
    return resp.json()["id"]
