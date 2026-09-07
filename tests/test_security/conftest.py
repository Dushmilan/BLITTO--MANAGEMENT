"""Shared fixtures for security tests (3-role model: user/director/md)."""

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
def md_token(client):
    return _register_and_login(client, "sec-md@pdn.ac.lk", Role.MD)


@pytest.fixture
def director_token(client):
    return _register_and_login(client, "sec-director@pdn.ac.lk", Role.DIRECTOR)


@pytest.fixture
def director_b_token(client):
    return _register_and_login(client, "sec-director-b@pdn.ac.lk", Role.DIRECTOR)


@pytest.fixture
def user_token(client):
    return _register_and_login(client, "sec-user@pdn.ac.lk", Role.USER)


@pytest.fixture
def auth_headers(md_token, director_token, user_token):
    return {
        Role.MD: {"Authorization": f"Bearer {md_token}"},
        Role.DIRECTOR: {"Authorization": f"Bearer {director_token}"},
        Role.USER: {"Authorization": f"Bearer {user_token}"},
    }


@pytest.fixture
def seed_app_id(client, md_token):
    resp = client.post(
        "/api/applications",
        json={
            "title": "Security Test Patent",
            "inventor_name": "Sec Tester",
            "inventor_email": "sec-user@pdn.ac.lk",
            "summary": "RBAC test",
        },
        headers={"Authorization": f"Bearer {md_token}"},
    )
    assert resp.status_code == 200, resp.text
    return resp.json()["id"]
