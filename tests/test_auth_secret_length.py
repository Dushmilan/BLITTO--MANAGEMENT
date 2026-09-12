"""TDD: auth secret must meet HS256 32-byte minimum."""

from app.core.config import settings


def test_auth_secret_meets_hs256_minimum() -> None:
    assert len(settings.auth_secret.encode()) >= 32, (
        f"auth_secret is {len(settings.auth_secret.encode())} bytes, need >= 32"
    )
