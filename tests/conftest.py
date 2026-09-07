"""Pytest configuration + shared fixtures for the BLITTO test suite."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

import pytest
from fastapi.testclient import TestClient

from app.domain.common import Role
from app.main import app
from app.modules.application_intake.models import Disclosure
from app.modules.authorization.models import RegisterRequest

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
