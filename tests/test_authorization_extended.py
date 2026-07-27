"""Authorization extended module-interface tests (local stub adapter)."""

import pytest

from app.domain.common import Role
from app.modules.authorization.local import LocalAuthorizationModule
from app.modules.authorization.models import LoginRequest, RegisterRequest


def _module() -> LocalAuthorizationModule:
    module = LocalAuthorizationModule()
    module.register(RegisterRequest(email="admin@blitto.edu", role=Role.ADMIN, password="adminpw"))
    module.register(RegisterRequest(email="inventor@uni.edu", role=Role.INVENTOR, password="invpw"))
    return module


def test_register_duplicate_email_raises() -> None:
    module = _module()
    with pytest.raises(ValueError, match="already registered"):
        module.register(RegisterRequest(email="admin@blitto.edu", role=Role.ADMIN))


def test_login_with_correct_credentials_returns_token() -> None:
    module = _module()
    token = module.login(LoginRequest(email="admin@blitto.edu", password="adminpw"))
    assert token is not None
    assert token.access_token


def test_login_with_wrong_password_returns_none() -> None:
    module = _module()
    token = module.login(LoginRequest(email="admin@blitto.edu", password="wrongpw"))
    assert token is None


def test_login_with_nonexistent_email_returns_none() -> None:
    module = _module()
    token = module.login(LoginRequest(email="ghost@blitto.edu", password="anything"))
    assert token is None


def test_list_users_returns_all_registered_users() -> None:
    module = _module()
    users = module.list_users()
    assert len(users) == 2


def test_issue_token_for_existing_email_returns_token() -> None:
    module = _module()
    token = module.issue_token("inventor@uni.edu")
    assert token is not None
    user = module.verify_token(token.access_token)
    assert user is not None
    assert user.email == "inventor@uni.edu"
    assert user.role == Role.INVENTOR


def test_verify_token_returns_correct_user() -> None:
    module = _module()
    token = module.issue_token("admin@blitto.edu")
    user = module.verify_token(token.access_token)
    assert user is not None
    assert user.email == "admin@blitto.edu"
    assert user.role == Role.ADMIN


def test_register_without_password_still_gets_token_via_issue() -> None:
    module = LocalAuthorizationModule()
    user = module.register(RegisterRequest(email="new@uni.edu", role=Role.INVENTOR))
    assert user.email == "new@uni.edu"
    # Without password, login() fails, but issue_token() (admin flow) works.
    assert module.login(LoginRequest(email="new@uni.edu", password="")) is None
    token = module.issue_token("new@uni.edu")
    assert token is not None
    assert module.verify_token(token.access_token) is not None
