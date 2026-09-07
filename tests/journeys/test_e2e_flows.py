"""Playwright end-to-end journeys (request API vs live server, black-box)."""

import io
import json
import time
import zipfile

import jwt

from tests.journeys.conftest import (
    journey_create_app,
    journey_download,
    journey_grant,
    journey_login,
    journey_register,
    journey_upload,
    unique_email,
)


def test_j1_full_grant_download_flow(api, journey_users) -> None:
    admin_token = journey_users["admin_token"]
    app_id = journey_create_app(api, admin_token, journey_users["inv_email"])
    up = journey_upload(api, admin_token, app_id, "grant.pdf", b"%PDF-1.4 granted")
    assert up.status == 200, up.text()
    grant = journey_grant(api, admin_token, app_id)
    assert grant.status == 200
    assert grant.json().get("warning") is None  # document present -> no warning
    dl = journey_download(api, journey_users["inv_token"], app_id)
    assert dl.status == 200, dl.text()
    assert dl.body() == b"%PDF-1.4 granted"
    assert "attachment" in dl.headers.get("content-disposition", "")
    assert "granted_" in dl.headers.get("content-disposition", "")
    assert dl.headers.get("x-downloaded-at"), "timestamp header missing"


def test_j2_pending_request_then_ready(api, journey_users) -> None:
    admin_token = journey_users["admin_token"]
    app_id = journey_create_app(api, admin_token, journey_users["inv_email"])
    grant = journey_grant(api, admin_token, app_id)
    assert grant.status == 200
    assert grant.json().get("warning"), "expected grant-without-document warning"

    dl = journey_download(api, journey_users["inv_token"], app_id)
    assert dl.status == 409
    assert dl.json()["detail"]["code"] == "document_pending"

    inv_h = {"Authorization": f"Bearer {journey_users['inv_token']}"}
    req1 = api.post(f"/applications/{app_id}/request-document", headers=inv_h)
    assert req1.status == 200, req1.text()
    req2 = api.post(f"/applications/{app_id}/request-document", headers=inv_h)
    assert req2.status == 429  # rate-limited: one open request

    up = journey_upload(api, admin_token, app_id, "late.pdf", b"%PDF-1.4 late")
    assert up.status == 200
    dl2 = journey_download(api, journey_users["inv_token"], app_id)
    assert dl2.status == 200
    assert dl2.body() == b"%PDF-1.4 late"


def test_j3_rejection_flow(api, journey_users) -> None:
    admin_token = journey_users["admin_token"]
    app_id = journey_create_app(api, admin_token, journey_users["inv_email"])
    rej = api.post(
        f"/applications/{app_id}/status?new_status=REJECTED",
        headers={"Authorization": f"Bearer {admin_token}"},
    )
    assert rej.status == 200
    inv_h = {"Authorization": f"Bearer {journey_users['inv_token']}"}
    listed = api.get("/applications", headers=inv_h).json()
    assert any(a["id"] == app_id and a["status"] == "REJECTED" for a in listed)
    dl = journey_download(api, journey_users["inv_token"], app_id)
    assert dl.status == 403  # download is GRANTED-only


def test_j4_multi_document_zip_download(api, journey_users) -> None:
    admin_token = journey_users["admin_token"]
    app_id = journey_create_app(api, admin_token, journey_users["inv_email"])
    for name in ("grant-a.pdf", "grant-b.pdf"):
        up = journey_upload(api, admin_token, app_id, name, b"%PDF-1.4 " + name.encode())
        assert up.status == 200
    assert journey_grant(api, admin_token, app_id).status == 200
    dl = journey_download(api, journey_users["inv_token"], app_id)
    assert dl.status == 200
    assert dl.headers.get("content-type") == "application/zip"
    with zipfile.ZipFile(io.BytesIO(dl.body())) as zf:
        assert sorted(zf.namelist()) == ["grant-a.pdf", "grant-b.pdf"]


def test_j5_expired_token_rejected(api, journey_users) -> None:  # noqa: ARG001
    expired = jwt.encode(
        {"sub": "x", "email": "md@pdn.ac.lk", "role": "md",
         "exp": int(time.time()) - 10},
        "change-me-in-production",
        algorithm="HS256",
    )
    r = api.get("/auth/me", headers={"Authorization": f"Bearer {expired}"})
    assert r.status == 401


def test_j6_second_user_isolation(api, journey_users) -> None:
    admin_token = journey_users["admin_token"]
    other_email = unique_email("other")
    journey_register(api, admin_token, other_email, "user", "otherpw-123")
    other_token = journey_login(api, other_email, "otherpw-123")
    app_id = journey_create_app(api, admin_token, journey_users["inv_email"])
    assert journey_grant(api, admin_token, app_id).status == 200
    other_h = {"Authorization": f"Bearer {other_token}"}
    assert api.get(f"/applications/{app_id}/download", headers=other_h).status == 403
    ids = {a["id"] for a in api.get("/applications", headers=other_h).json()}
    assert app_id not in ids
    # Registration still requires authentication (no anonymous signup).
    anon = api.post(
        "/auth/register",
        data=json.dumps({"email": unique_email("anon"), "role": "user"}),
        headers={"Content-Type": "application/json"},
    )
    assert anon.status == 401
