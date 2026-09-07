"""RBAC matrix: every protected endpoint x every role (user/director/md)."""

import pytest

from app.domain.common import Role

STAFF = [Role.MD, Role.DIRECTOR]

# (endpoint, method, body_factory, params_factory, allowed_roles)
# body_factory and params_factory take (app_id) -> dict
ENDPOINTS = [
    # ── MD-only endpoints ──
    pytest.param(
        "POST", "/api/auth/register",
        lambda _: {"email": "new@pdn.ac.lk", "role": "director", "password": "pw"},
        None, [Role.MD], id="POST /auth/register (staff creation)",
    ),
    pytest.param(
        "POST", "/api/auth/token", None, None,
        [Role.MD], id="POST /auth/token",
    ),
    pytest.param(
        "GET", "/api/users", None, lambda _: None,
        [Role.MD], id="GET /users",
    ),
    # ── Staff endpoints (director + md) ──
    pytest.param(
        "POST", "/api/applications/{app_id}/status",
        None, lambda _: {"new_status": "FILED"},
        STAFF, id="POST /applications/{id}/status",
    ),
    pytest.param(
        "POST", "/api/applications/{app_id}/office-actions",
        None, lambda _: {"kind": "REJECTION", "body": "test"},
        STAFF, id="POST /applications/{id}/office-actions",
    ),
    pytest.param(
        "POST", "/api/applications/{app_id}/notify",
        None, lambda _: {"subject": "Test", "body": "Test body"},
        STAFF, id="POST /applications/{id}/notify",
    ),
    pytest.param(
        "POST", "/api/admin/filing/{app_id}/file",
        None, lambda _: None,
        STAFF, id="POST /admin/filing/{id}/file",
    ),
    pytest.param(
        "POST", "/api/admin/filing/{app_id}/acknowledge",
        None, lambda _: None,
        STAFF, id="POST /admin/filing/{id}/acknowledge",
    ),
    pytest.param(
        "POST", "/api/admin/filing/{app_id}/grant",
        None, lambda _: {"patent_number": "LK/PAT/2026/001"},
        STAFF, id="POST /admin/filing/{id}/grant",
    ),
    pytest.param(
        "POST", "/api/admin/filing/{app_id}/reject", None, lambda _: None,
        STAFF, id="POST /admin/filing/{id}/reject",
    ),
    pytest.param(
        "GET", "/api/admin/filing/{app_id}/status", None, lambda _: None,
        STAFF, id="GET /admin/filing/{id}/status",
    ),
    pytest.param(
        "POST", "/api/admin/filing/{app_id}/defect-sheets",
        None, lambda _: {"sheet_number": 1, "description": "test"},
        STAFF, id="POST /admin/filing/{id}/defect-sheets",
    ),
    pytest.param(
        "GET", "/api/admin/filing/{app_id}/defect-sheets", None, lambda _: None,
        STAFF, id="GET /admin/filing/{id}/defect-sheets",
    ),
    pytest.param(
        "POST", "/api/applications",
        lambda _: {"title": "New", "inventor_name": "A", "inventor_email": "a@pdn.ac.lk", "summary": ""},
        None, STAFF,
        id="POST /applications",
    ),
    pytest.param(
        "GET", "/api/analytics/portfolio", None, None,
        STAFF,
        id="GET /analytics/portfolio",
    ),
    pytest.param(
        "GET", "/api/vault/status", None, None,
        STAFF,
        id="GET /vault/status",
    ),
    pytest.param(
        "POST", "/api/vault/lock", None, None,
        STAFF,
        id="POST /vault/lock",
    ),
    pytest.param(
        "POST", "/api/vault/unlock",
        lambda _: {"master_key": "dummy"},
        None, STAFF,
        id="POST /vault/unlock",
    ),
    pytest.param(
        "GET", "/api/applications/{app_id}/documents", None, None,
        STAFF,
        id="GET /applications/{id}/documents",
    ),
    # ── Authenticated-only (any role) ──
    pytest.param(
        "GET", "/api/auth/me", None, None, list(Role),
        id="GET /auth/me",
    ),
    pytest.param(
        "GET", "/api/notifications", None, None, list(Role),
        id="GET /notifications",
    ),
]


@pytest.mark.parametrize("method,path_tpl,body_factory,params_factory,allowed_roles", ENDPOINTS)
def test_rbac_enforcement(
    client, auth_headers, seed_app_id,
    method, path_tpl, body_factory, params_factory, allowed_roles,
):
    """Every role gets 200 for allowed endpoints and 403 for disallowed ones."""
    app_id = seed_app_id
    path = path_tpl.replace("{app_id}", app_id)

    for role in Role:
        headers = auth_headers[role]
        body = body_factory(app_id) if body_factory else None
        params = params_factory(app_id) if params_factory else None

        if method == "POST":
            resp = client.post(path, json=body, params=params, headers=headers)
        elif method == "GET":
            resp = client.get(path, params=params, headers=headers)
        else:
            raise ValueError(f"Unknown method {method}")

        if role in allowed_roles:
            assert resp.status_code not in (401, 403), (
                f"{role.value} should be allowed on {path_tpl} but got {resp.status_code}: {resp.text}"
            )
        else:
            assert resp.status_code == 403, (
                f"{role.value} should be denied on {path_tpl} but got {resp.status_code}: {resp.text}"
            )


ROLE_UPLOAD_ENDPOINTS = [
    pytest.param(Role.MD, 200, id="md upload"),
    pytest.param(Role.DIRECTOR, 200, id="director upload"),
    pytest.param(Role.USER, 403, id="user upload blocked"),
]


@pytest.mark.parametrize("role,expected_status", ROLE_UPLOAD_ENDPOINTS)
def test_document_upload_rbac(client, auth_headers, seed_app_id, role, expected_status):
    resp = client.post(
        f"/api/applications/{seed_app_id}/documents?filename=test.pdf",
        headers=auth_headers[role],
        content=b"test content",
    )
    assert resp.status_code == expected_status, (
        f"{role.value} upload expected {expected_status} got {resp.status_code}: {resp.text}"
    )


def test_unauthenticated_access_returns_401(client, seed_app_id):
    """Every protected endpoint returns 401 without a bearer token."""
    protected = [
        ("GET", "/api/auth/me"),
        ("GET", "/api/users"),
        ("GET", "/api/applications"),
        ("POST", "/api/applications"),
        ("GET", "/api/analytics/portfolio"),
        ("GET", "/api/vault/status"),
        ("POST", "/api/vault/lock"),
        ("GET", "/api/notifications"),
        ("GET", f"/api/applications/{seed_app_id}/documents"),
        ("POST", f"/api/admin/filing/{seed_app_id}/file"),
    ]
    for method, path in protected:
        if method == "GET":
            resp = client.get(path)
        else:
            resp = client.post(path)
        assert resp.status_code == 401, (
            f"{method} {path} expected 401 without auth, got {resp.status_code}"
        )
