"""API RBAC contract matrix: every route x role -> expected status code.

Roles: user (own patents only), director + md (shared staff powers),
md-only (user management, token minting).
"""

import uuid

from tests.conftest import auth_headers

DISCLOSURE = {
    "inventor_name": "Matrix User",
    "title": "Matrix Widget",
    "summary": "s",
}


def _unique_email(tag: str) -> str:
    return f"matrix-{tag}-{uuid.uuid4().hex[:8]}@pdn.ac.lk"


def test_auth_me_matrix(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    assert c.get("/auth/me").status_code == 401
    for name in ("md", "director", "user_a"):
        r = c.get("/auth/me", headers=auth_headers(tokens[name]))
        assert r.status_code == 200, (name, r.text[:200])


def test_register_privilege_matrix(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    # Anonymous cannot register (auth required).
    assert c.post("/auth/register", json={"email": _unique_email("a")}).status_code == 401
    # User may self-register user role.
    r = c.post(
        "/auth/register",
        json={"email": _unique_email("b"), "role": "user"},
        headers=auth_headers(tokens["user_a"]),
    )
    assert r.status_code == 200, r.text[:200]
    # User may not create director/md roles.
    for role in ("director", "md"):
        r = c.post(
            "/auth/register",
            json={"email": _unique_email(f"c-{role}"), "role": role},
            headers=auth_headers(tokens["user_a"]),
        )
        assert r.status_code == 403, role
    # Director may not create director/md roles either (MD-only).
    r = c.post(
        "/auth/register",
        json={"email": _unique_email("d"), "role": "director"},
        headers=auth_headers(tokens["director"]),
    )
    assert r.status_code == 403
    # MD may create director and md roles.
    for role in ("director", "md"):
        r = c.post(
            "/auth/register",
            json={"email": _unique_email(f"e-{role}"), "role": role},
            headers=auth_headers(tokens["md"]),
        )
        assert r.status_code == 200, (role, r.text[:200])
    # Non-institution mail rejected.
    r = c.post(
        "/auth/register",
        json={"email": "x@gmail.com", "role": "user"},
        headers=auth_headers(tokens["md"]),
    )
    assert r.status_code == 422


def test_token_issue_matrix(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    assert c.post("/auth/token").status_code == 401
    assert c.post("/auth/token", headers=auth_headers(tokens["md"])).status_code == 200
    assert c.post("/auth/token", headers=auth_headers(tokens["director"])).status_code == 403
    assert c.post("/auth/token", headers=auth_headers(tokens["user_a"])).status_code == 403


def test_applications_matrix(seeded) -> None:
    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    body = {**DISCLOSURE, "inventor_email": _unique_email("inv")}
    assert c.post("/applications", json=body).status_code == 401
    for role in ("director", "md"):
        r = c.post("/applications", json={**body, "inventor_email": _unique_email(role)},
                   headers=auth_headers(tokens[role]))
        assert r.status_code == 200, (role, r.text[:200])
    r = c.post("/applications", json=body, headers=auth_headers(tokens["user_a"]))
    assert r.status_code == 403
    # User A sees only their own application.
    r = c.get("/applications", headers=auth_headers(tokens["user_a"]))
    assert r.status_code == 200
    assert {a["id"] for a in r.json()} == {app_id}
    assert c.get("/applications").status_code == 401


def test_docketing_matrix(seeded) -> None:
    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    params = {"type": "RESPONSE", "due_date": "2027-01-01T00:00:00Z"}
    assert c.post(f"/applications/{app_id}/deadlines", params=params).status_code == 401
    for role in ("director", "md"):
        r = c.post(
            f"/applications/{app_id}/deadlines",
            params=params,
            headers=auth_headers(tokens[role]),
        )
        assert r.status_code == 200, (role, r.text[:200])
    r = c.post(
        f"/applications/{app_id}/deadlines",
        params=params,
        headers=auth_headers(tokens["user_a"]),
    )
    assert r.status_code == 403
    assert c.get("/deadlines").status_code == 401
    assert c.get("/deadlines", headers=auth_headers(tokens["md"])).status_code == 200
    r = c.get("/deadlines", headers=auth_headers(tokens["user_a"]))
    assert r.status_code == 403


def test_documents_matrix(seeded) -> None:
    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    url = f"/applications/{app_id}/documents"
    pdf = (b"%PDF-1.4 x", "application/pdf")
    assert c.post(url, params={"filename": "a.pdf"}, content=pdf[0]).status_code == 401
    for role, expected in (("md", 200), ("director", 200), ("user_a", 403)):
        r = c.post(
            url,
            params={"filename": f"{role}.pdf"},
            content=pdf[0],
            headers={**auth_headers(tokens[role]), "Content-Type": pdf[1]},
        )
        assert r.status_code == expected, (role, r.text[:200])
    assert c.get(url).status_code == 401
    assert c.get(url, headers=auth_headers(tokens["md"])).status_code == 200
    assert c.get(url, headers=auth_headers(tokens["user_a"])).status_code == 403


def test_missing_documents_matrix(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    url = "/applications/missing-documents"
    assert c.get(url).status_code == 401
    # Director and MD share the worklist.
    assert c.get(url, headers=auth_headers(tokens["md"])).status_code == 200
    assert c.get(url, headers=auth_headers(tokens["director"])).status_code == 200
    assert c.get(url, headers=auth_headers(tokens["user_a"])).status_code == 403


def test_prosecution_and_status_matrix(seeded) -> None:
    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    oa = f"/applications/{app_id}/office-actions"
    params = {"kind": "REJECTION", "body": "prior art"}
    assert c.post(oa, params=params).status_code == 401
    for role in ("director", "md"):
        r = c.post(oa, params=params, headers=auth_headers(tokens[role]))
        assert r.status_code == 200, (role, r.text[:200])
    r = c.post(oa, params=params, headers=auth_headers(tokens["user_a"]))
    assert r.status_code == 403
    st = f"/applications/{app_id}/status"
    assert c.post(st, params={"new_status": "FILED"}).status_code == 401
    # Director and MD share status-transition power.
    for role in ("director", "md"):
        r = c.post(st, params={"new_status": "FILED"}, headers=auth_headers(tokens[role]))
        assert r.status_code == 200, (role, r.text[:200])
    r = c.post(
        st, params={"new_status": "FILED"}, headers=auth_headers(tokens["user_a"])
    )
    assert r.status_code == 403


def test_notify_and_analytics_matrix(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    params = {
        "recipient_email": "user-a@pdn.ac.lk",
        "application_ref": "app-1",
        "new_status": "FILED",
    }
    assert c.post("/notify/status-change", params=params).status_code == 401
    r = c.post(
        "/notify/status-change", params=params, headers=auth_headers(tokens["user_a"])
    )
    assert r.status_code == 200, r.text[:200]
    for url in ("/analytics/portfolio", "/analytics/deadlines"):
        assert c.get(url).status_code == 401
        assert c.get(url, headers=auth_headers(tokens["md"])).status_code == 200
        assert c.get(url, headers=auth_headers(tokens["director"])).status_code == 200
        assert c.get(url, headers=auth_headers(tokens["user_a"])).status_code == 403
