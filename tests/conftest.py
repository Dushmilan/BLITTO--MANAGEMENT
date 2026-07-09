"""Pytest configuration and shared fixtures.

Provides:
- `sys.path` wiring so `from app...` resolves.
- A FastAPI `TestClient` bound to the real app (runs lifespan -> fresh in-memory
  module state per test, giving full isolation between tests).
- Role-scoped auth header fixtures (admin/attorney/paralegal/inventor) and a
  `make_user` factory for ad-hoc users.
"""

from __future__ import annotations

import sys
from pathlib import Path
from typing import Optional

import pytest
from fastapi.testclient import TestClient

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from app.domain.common import Role  # noqa: E402
from app.main import app  # noqa: E402
from app.modules.authorization.models import (  # noqa: E402
    LoginRequest,
    RegisterRequest,
)

INVENTOR_EMAIL = "inventor@peradeniya.lk"


@pytest.fixture
def client():
    """A TestClient whose lifespan wires fresh in-memory modules per test."""
    with TestClient(app) as test_client:
        yield test_client


def _register_and_token(test_client: TestClient, email: str, role: Role,
                        password: str = "pw", user_code: Optional[str] = None) -> str:
    auth = test_client.app.state.authorization
    if not any(u.email == email for u in auth._users.values()):
        auth.register(
            RegisterRequest(email=email, role=role, password=password,
                            user_code=user_code)
        )
    token = auth.login(LoginRequest(email=email, password=password))
    assert token is not None, f"failed to log in seeded user {email}"
    return token.access_token


def _headers(token: str) -> dict:
    return {"Authorization": f"Bearer {token}"}


@pytest.fixture
def make_user(client):
    """Factory: create a user with a given role and return its auth headers."""

    def _factory(role: Role, email: Optional[str] = None, password: str = "pw",
                 user_code: Optional[str] = None) -> dict:
        addr = email or f"{role.value}@peradeniya.lk"
        token = _register_and_token(client, addr, role, password, user_code)
        return _headers(token)

    return _factory


@pytest.fixture
def admin_headers(client):
    return _headers(_register_and_token(client, "admin@peradeniya.lk", Role.ADMIN))


@pytest.fixture
def attorney_headers(client):
    return _headers(
        _register_and_token(client, "attorney@peradeniya.lk", Role.ATTORNEY)
    )


@pytest.fixture
def paralegal_headers(client):
    return _headers(
        _register_and_token(client, "paralegal@peradeniya.lk", Role.PARALEGAL)
    )


@pytest.fixture
def inventor_headers(client):
    return _headers(
        _register_and_token(client, INVENTOR_EMAIL, Role.INVENTOR)
    )


@pytest.fixture
def disclosure_payload():
    """A valid Disclosure body pointing at the seeded inventor."""
    return {
        "inventor_name": "Dr. Ada Perera",
        "inventor_email": INVENTOR_EMAIL,
        "title": "Solar Desalination Membrane",
        "summary": "A low-cost membrane for solar-powered desalination.",
    }


@pytest.fixture
def seed_application(client, admin_headers, disclosure_payload):
    """Create one application via the API (admin) and return its JSON dict."""
    resp = client.post("/api/applications", json=disclosure_payload,
                       headers=admin_headers)
    assert resp.status_code == 200, resp.text
    return resp.json()
