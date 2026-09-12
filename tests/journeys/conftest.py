"""Playwright journey fixtures: live uvicorn server + API request context.

Browser binaries are NOT required: journeys use Playwright's request API
against a real HTTP server (black-box, closest to what a browser would do).
"""

import json
import os
import socket
import subprocess
import sys
import time
import urllib.request
import uuid
from pathlib import Path

import pytest
from playwright.sync_api import Playwright

ROOT = Path(__file__).resolve().parent.parent.parent

BOOTSTRAP_EMAIL = "md@pdn.ac.lk"
BOOTSTRAP_PW = "journey-md-pw"


def _free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


@pytest.fixture(scope="session")
def live_server():
    port = _free_port()
    env = {
        **os.environ,
        "BLITTO_BOOTSTRAP_ADMIN_EMAIL": BOOTSTRAP_EMAIL,
        "BLITTO_BOOTSTRAP_ADMIN_PASSWORD": BOOTSTRAP_PW,
    }
    proc = subprocess.Popen(
        [sys.executable, "-m", "uvicorn", "app.main:app",
         "--host", "127.0.0.1", "--port", str(port)],
        cwd=str(ROOT),
        env=env,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    base = f"http://127.0.0.1:{port}"
    try:
        deadline = time.time() + 30
        while time.time() < deadline:
            try:
                with urllib.request.urlopen(base + "/health", timeout=2) as r:
                    if r.status == 200:
                        break
            except OSError:
                time.sleep(0.2)
        else:
            raise RuntimeError("live server did not start")
        yield base
    finally:
        proc.terminate()
        proc.wait(timeout=15)


@pytest.fixture()
def api(playwright: Playwright, live_server: str):
    ctx = playwright.request.new_context(base_url=live_server)
    yield ctx
    ctx.dispose()


def _post_json(api, url: str, payload: dict, token: str | None = None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = f"Bearer {token}"
    return api.post(url, data=json.dumps(payload), headers=headers)


def journey_login(api, email: str, password: str) -> str:
    resp = _post_json(api, "/auth/login", {"email": email, "password": password})
    assert resp.status == 200, resp.text()
    return resp.json()["access_token"]


def journey_register(api, admin_token: str, email: str, role: str, password: str) -> None:
    resp = _post_json(
        api,
        "/auth/register",
        {"email": email, "role": role, "password": password},
        token=admin_token,
    )
    assert resp.status == 200, resp.text()


def unique_email(tag: str) -> str:
    return f"j-{tag}-{uuid.uuid4().hex[:8]}@pdn.ac.lk"


@pytest.fixture()
def journey_users(api, live_server):  # noqa: ARG001
    """Bootstrap-MD login + fresh user; returns tokens + emails."""
    admin_token = journey_login(api, BOOTSTRAP_EMAIL, BOOTSTRAP_PW)
    inv_email = unique_email("inv")
    journey_register(api, admin_token, inv_email, "user", "invpw-123")
    return {
        "admin_token": admin_token,
        "inv_email": inv_email,
        "inv_token": journey_login(api, inv_email, "invpw-123"),
    }


def journey_create_app(api, admin_token: str, inventor_email: str, title: str = "Journey Widget") -> str:
    resp = _post_json(
        api,
        "/applications",
        {"inventor_name": "Journey Inv", "inventor_email": inventor_email,
         "title": title, "summary": "s"},
        token=admin_token,
    )
    assert resp.status == 200, resp.text()
    return resp.json()["id"]


def journey_upload(api, admin_token: str, app_id: str, filename: str, content: bytes):
    return api.post(
        f"/applications/{app_id}/documents?filename={filename}",
        data=content,
        headers={"Content-Type": "application/pdf",
                 "Authorization": f"Bearer {admin_token}"},
    )


def journey_grant(api, admin_token: str, app_id: str):
    last = None
    for status in ("FILED", "ACKNOWLEDGED", "EXAMINATION", "GRANTED"):
        last = api.post(
            f"/applications/{app_id}/status?new_status={status}",
            headers={"Authorization": f"Bearer {admin_token}"},
        )
        assert last.status == 200, last.text()
    return last


def journey_download(api, token: str, app_id: str):
    return api.get(
        f"/applications/{app_id}/download",
        headers={"Authorization": f"Bearer {token}"},
    )
