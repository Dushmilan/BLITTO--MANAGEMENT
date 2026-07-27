"""Token security tests — expired, tampered, malformed tokens at HTTP level."""

import time

import jwt

from app.core.config import settings


def _admin_token(client):
    resp = client.post("/api/auth/login", json={"email": "admin@blitto.local", "password": "admin1234"})
    assert resp.status_code == 200
    return resp.json()["access_token"]


def test_expired_token_rejected(client):
    expired = jwt.encode(
        {"sub": "x", "email": "admin@blitto.local", "role": "admin", "exp": int(time.time()) - 10},
        settings.auth_secret, algorithm="HS256",
    )
    resp = client.get("/api/auth/me", headers={"Authorization": f"Bearer {expired}"})
    assert resp.status_code == 401
    assert "expired" in resp.text.lower() or "invalid" in resp.text.lower()


def test_tampered_token_rejected(client):
    token = _admin_token(client)
    parts = token.split(".")
    tampered = parts[0] + "." + parts[1] + ".AAAA"  # corrupted signature
    resp = client.get("/api/auth/me", headers={"Authorization": f"Bearer {tampered}"})
    assert resp.status_code == 401


def test_wrong_signature_token_rejected(client):
    token = jwt.encode(
        {"sub": "x", "email": "admin@blitto.local", "role": "admin", "exp": int(time.time()) + 3600},
        "different-secret", algorithm="HS256",
    )
    resp = client.get("/api/auth/me", headers={"Authorization": f"Bearer {token}"})
    assert resp.status_code == 401


def test_malformed_token_rejected(client):
    resp = client.get("/api/auth/me", headers={"Authorization": "Bearer not-a-valid-jwt-at-all"})
    assert resp.status_code == 401


def test_missing_authorization_header(client):
    resp = client.get("/api/auth/me")
    assert resp.status_code == 401


def test_empty_bearer_token(client):
    resp = client.get("/api/auth/me", headers={"Authorization": "Bearer "})
    assert resp.status_code == 401


def test_wrong_auth_scheme(client):
    resp = client.get("/api/auth/me", headers={"Authorization": "Basic dXNlcjpwYXNz"})
    assert resp.status_code == 401


def test_token_from_different_user_not_accepted_for_admin_ops(client):
    """An inventor token cannot access admin endpoints."""
    inv_resp = client.post("/api/auth/login", json={"email": "inventor1@peradeniya.lk", "password": "password"})
    assert inv_resp.status_code == 200
    inv_token = inv_resp.json()["access_token"]
    resp = client.get("/api/users", headers={"Authorization": f"Bearer {inv_token}"})
    assert resp.status_code == 403
