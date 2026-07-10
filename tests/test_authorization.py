"""Authorization module-interface tests (local stub adapter)."""

from app.modules.authorization.local import LocalAuthorizationModule
from app.modules.authorization.models import RegisterRequest
from app.domain.common import Role


def _module() -> LocalAuthorizationModule:
    module = LocalAuthorizationModule()
    module.register(RegisterRequest(email="admin@blitto.edu", role=Role.ADMIN))
    return module


def test_register_then_issue_and_verify_token() -> None:
    module = _module()
    token = module.issue_token("admin@blitto.edu")
    assert token is not None
    user = module.verify_token(token.access_token)  # type: ignore[union-attr]
    assert user is not None
    assert user.email == "admin@blitto.edu"
    assert user.role == Role.ADMIN


def test_verify_invalid_token_returns_none() -> None:
    module = _module()
    assert module.verify_token("not-a-real-token") is None


def test_issue_token_unknown_email_returns_none() -> None:
    module = _module()
    assert module.issue_token("ghost@blitto.edu") is None


def test_list_users_returns_registered_users() -> None:
    module = _module()
    users = module.list_users()
    assert len(users) == 1
    assert users[0].email == "admin@blitto.edu"
