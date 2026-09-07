"""Security tests: isolation, token integrity, upload limits, filename safety."""

import time

import jwt

from app.core.config import settings
from tests.conftest import auth_headers


def test_user_cannot_see_or_download_other_user_patent(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    other = app_state_app(c, "user-b@pdn.ac.lk")
    # Listing is filtered.
    mine = c.get("/applications", headers=auth_headers(tokens["user_a"])).json()
    assert other not in {a["id"] for a in mine}
    # Grant B's app, then A must still be refused.
    c.post(
        f"/applications/{other}/status",
        params={"new_status": "GRANTED"},
        headers=auth_headers(tokens["md"]),
    )
    r = c.get(f"/applications/{other}/download", headers=auth_headers(tokens["user_a"]))
    assert r.status_code == 403


def app_state_app(c, inventor_email: str) -> str:
    from app.main import app
    from app.modules.application_intake.models import Disclosure

    application = app.state.application_intake.create_application_shell(
        Disclosure(
            inventor_name="Other",
            inventor_email=inventor_email,
            title="Other widget",
            summary="s",
        )
    )
    return application.id


def test_tampered_and_expired_tokens_rejected(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    good = tokens["md"]
    # Tampered signature.
    assert (
        c.get("/auth/me", headers=auth_headers(good + "tampered")).status_code == 401
    )
    # Expired token signed with the server secret.
    expired = jwt.encode(
        {"sub": "x", "email": "md@pdn.ac.lk", "role": "md",
         "exp": int(time.time()) - 10},
        settings.auth_secret,
        algorithm="HS256",
    )
    assert c.get("/auth/me", headers=auth_headers(expired)).status_code == 401
    # Wrong-secret token.
    forged = jwt.encode(
        {"sub": "x", "email": "md@pdn.ac.lk", "role": "md",
         "exp": int(time.time()) + 3600},
        "wrong-secret",
        algorithm="HS256",
    )
    assert c.get("/auth/me", headers=auth_headers(forged)).status_code == 401


def test_oversized_upload_rejected(seeded) -> None:
    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    big = b"x" * (10 * 1024 * 1024 + 1)
    r = c.post(
        f"/applications/{app_id}/documents",
        params={"filename": "big.pdf"},
        content=big,
        headers={**auth_headers(tokens["md"]), "Content-Type": "application/pdf"},
    )
    assert r.status_code == 413


def test_path_traversal_filename_sanitized(seeded) -> None:
    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    c.post(
        f"/applications/{app_id}/documents",
        params={"filename": "../../../evil.pdf"},
        content=b"%PDF-1.4 evil",
        headers={**auth_headers(tokens["md"]), "Content-Type": "application/pdf"},
    )
    c.post(
        f"/applications/{app_id}/status",
        params={"new_status": "GRANTED"},
        headers=auth_headers(tokens["md"]),
    )
    r = c.get(f"/applications/{app_id}/download", headers=auth_headers(tokens["md"]))
    assert r.status_code == 200
    disposition = r.headers.get("content-disposition", "")
    assert "../" not in disposition and "\\" not in disposition


def test_login_rejects_bad_credentials_and_foreign_mail(seeded) -> None:
    c = seeded["client"]
    r = c.post("/auth/login", json={"email": "md@pdn.ac.lk", "password": "wrong"})
    assert r.status_code == 401
    r = c.post("/auth/login", json={"email": "a@gmail.com", "password": "x"})
    assert r.status_code in (403, 422)
