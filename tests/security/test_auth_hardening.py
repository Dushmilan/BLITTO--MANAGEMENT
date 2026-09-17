"""TDD RED: auth hardening (issue #50) — fail-fast secret, hashed passwords, login throttling."""

import pytest

from app.core.config import DEFAULT_AUTH_SECRET, Settings
from app.domain.common import Role
from app.modules.authorization.local import LocalAuthorizationModule
from app.modules.authorization.models import LoginRequest, RegisterRequest


def _module() -> LocalAuthorizationModule:
    return LocalAuthorizationModule(secret="test-secret-not-the-default-value-123")


def test_default_secret_rejected_outside_local_env() -> None:
    s = Settings(environment="production", auth_secret=DEFAULT_AUTH_SECRET)
    with pytest.raises(RuntimeError):
        s.ensure_production_ready()


def test_default_secret_allowed_in_local_env() -> None:
    s = Settings(environment="local", auth_secret=DEFAULT_AUTH_SECRET)
    s.ensure_production_ready()  # must not raise


def test_custom_secret_allowed_in_production() -> None:
    s = Settings(environment="production", auth_secret="a" * 40)
    s.ensure_production_ready()  # must not raise


def test_passwords_not_stored_plaintext() -> None:
    module = _module()
    user = module.register(
        RegisterRequest(email="hash@pdn.ac.lk", role=Role.USER, password="s3cret-pw")
    )
    stored = module._passwords[user.id]
    assert stored != "s3cret-pw"
    assert stored.startswith("$2b$")


def test_login_roundtrip_with_hashed_password() -> None:
    module = _module()
    module.register(
        RegisterRequest(email="roundtrip@pdn.ac.lk", role=Role.USER, password="s3cret-pw")
    )
    assert module.login(LoginRequest(email="roundtrip@pdn.ac.lk", password="s3cret-pw")) is not None
    assert module.login(LoginRequest(email="roundtrip@pdn.ac.lk", password="wrong")) is None


def test_overlong_password_rejected_at_register() -> None:
    module = _module()
    with pytest.raises(ValueError):
        module.register(
            RegisterRequest(email="long@pdn.ac.lk", role=Role.USER, password="x" * 73)
        )


def test_repeated_failed_logins_are_throttled(client) -> None:
    from app.main import app

    email = "throttle-victim@pdn.ac.lk"
    app.state.authorization.register(
        RegisterRequest(email=email, role=Role.USER, password="right-pw")
    )
    for _ in range(5):
        r = client.post("/auth/login", json={"email": email, "password": "wrong-pw"})
        assert r.status_code == 401
    r = client.post("/auth/login", json={"email": email, "password": "wrong-pw"})
    assert r.status_code == 429
    assert r.headers.get("Retry-After") == "600"
    # Even the correct password is refused while throttled.
    r = client.post("/auth/login", json={"email": email, "password": "right-pw"})
    assert r.status_code == 429
