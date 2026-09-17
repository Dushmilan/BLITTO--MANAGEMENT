"""TDD RED: token refresh endpoint (issue #41 backend slice)."""

from tests.conftest import auth_headers


def test_refresh_reissues_token_for_valid_session(client, md_headers) -> None:
    r = client.post("/auth/refresh", headers=md_headers)
    assert r.status_code == 200, r.text[:300]
    fresh = r.json()["access_token"]
    me = client.get("/auth/me", headers=auth_headers(fresh))
    assert me.status_code == 200
    assert me.json()["email"] == "md@pdn.ac.lk"


def test_refresh_rejects_missing_or_bad_token(client) -> None:
    assert client.post("/auth/refresh").status_code == 401
    assert (
        client.post(
            "/auth/refresh", headers=auth_headers("bogus.token.here")
        ).status_code
        == 401
    )
