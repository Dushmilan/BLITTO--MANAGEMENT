"""Pytest configuration + shared fixtures for the BLITTO test suite."""

from __future__ import annotations

import sys
from pathlib import Path
from typing import Optional

import pytest
from fastapi.testclient import TestClient

from app.domain.common import Role
from app.main import app
from app.modules.application_intake.models import Disclosure
from app.modules.authorization.models import LoginRequest, RegisterRequest

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

MD_EMAIL = "md@pdn.ac.lk"
MD_PW = "mdpw-123"


def _login(client: TestClient, email: str, password: str) -> str:
    resp = client.post("/auth/login", json={"email": email, "password": password})
    assert resp.status_code == 200, resp.text[:300]
    return resp.json()["access_token"]


@pytest.fixture()
def tc():
    """Function-scoped TestClient with fresh in-memory state (lifespan rewired)."""
    with TestClient(app) as client:
        yield client


@pytest.fixture()
def client(tc: TestClient):
    """Alias of `tc` for tests written against a `client` fixture."""
    return tc


@pytest.fixture()
def seeded(tc: TestClient):
    """Seeded users for all three roles + a second user; returns tokens and app id."""
    auth = app.state.authorization
    users = {
        "md": (MD_EMAIL, Role.MD, MD_PW),
        "director": ("director@pdn.ac.lk", Role.DIRECTOR, "dirpw-123"),
        "director_b": ("director-b@pdn.ac.lk", Role.DIRECTOR, "dirbpw-123"),
        "user_a": ("user-a@pdn.ac.lk", Role.USER, "userapw-123"),
        "user_b": ("user-b@pdn.ac.lk", Role.USER, "userbpw-123"),
    }
    tokens = {}
    for name, (email, role, pw) in users.items():
        auth.register(RegisterRequest(email=email, role=role, password=pw))
        tokens[name] = _login(tc, email, pw)
    application = app.state.application_intake.create_application_shell(
        Disclosure(
            inventor_name="User A",
            inventor_email="user-a@pdn.ac.lk",
            title="Widget",
            summary="s",
        )
    )
    return {"client": tc, "tokens": tokens, "app_id": application.id}


def auth_headers(token: str) -> dict[str, str]:
    return {"Authorization": f"Bearer {token}"}


def _register_and_token(
    test_client: TestClient,
    email: str,
    role: Role,
    password: str = "pw",
    user_code: Optional[str] = None,
) -> str:
    auth = test_client.app.state.authorization
    if not any(u.email == email for u in auth._users.values()):
        auth.register(
            RegisterRequest(email=email, role=role, password=password,
                            user_code=user_code)
        )
    token = auth.login(LoginRequest(email=email, password=password))
    assert token is not None, f"failed to log in seeded user {email}"
    return token.access_token


@pytest.fixture()
def make_user(client):
    """Factory: create a user with a given role and return its auth headers."""

    def _factory(
        role: Role,
        email: Optional[str] = None,
        password: str = "pw",
        user_code: Optional[str] = None,
    ) -> dict:
        addr = email or f"{role.value}@pdn.ac.lk"
        token = _register_and_token(client, addr, role, password, user_code)
        return auth_headers(token)

    return _factory


@pytest.fixture()
def md_headers(client):
    return auth_headers(_register_and_token(client, MD_EMAIL, Role.MD, MD_PW))


@pytest.fixture()
def director_headers(client):
    return auth_headers(
        _register_and_token(client, "director@pdn.ac.lk", Role.DIRECTOR)
    )


@pytest.fixture()
def user_headers(client):
    return auth_headers(
        _register_and_token(client, "user-a@pdn.ac.lk", Role.USER)
    )


@pytest.fixture()
def disclosure_payload():
    """A valid Disclosure body pointing at the seeded user."""
    return {
        "inventor_name": "Dr. Ada Perera",
        "inventor_email": "user-a@pdn.ac.lk",
        "title": "Solar Desalination Membrane",
        "summary": "A low-cost membrane for solar-powered desalination.",
    }


@pytest.fixture()
def seed_application(client, md_headers, disclosure_payload):
    """Create one application via the API (md) and return its JSON dict."""
    resp = client.post("/applications", json=disclosure_payload, headers=md_headers)
    assert resp.status_code == 200, resp.text
    return resp.json()
