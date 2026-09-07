"""Authorization extended module-interface tests (local stub adapter)."""

import pytest

from app.domain.common import Role
from app.modules.authorization.local import LocalAuthorizationModule
from app.modules.authorization.models import LoginRequest, RegisterRequest


def _module() -> LocalAuthorizationModule:
    module = LocalAuthorizationModule()
    module.register(RegisterRequest(email="md@pdn.ac.lk", role=Role.MD, password="adminpw"))
    module.register(RegisterRequest(email="user@pdn.ac.lk", role=Role.USER, password="invpw"))
    return module


def test_register_duplicate_email_raises() -> None:
    module = _module()
    with pytest.raises(ValueError, match="already registered"):
        module.register(RegisterRequest(email="md@pdn.ac.lk", role=Role.MD))


def test_login_with_correct_credentials_returns_token() -> None:
    module = _module()
    token = module.login(LoginRequest(email="md@pdn.ac.lk", password="adminpw"))
    assert token is not None
    assert token.access_token


def test_login_with_wrong_password_returns_none() -> None:
    module = _module()
    token = module.login(LoginRequest(email="md@pdn.ac.lk", password="wrongpw"))
    assert token is None


def test_login_with_nonexistent_email_returns_none() -> None:
    module = _module()
    token = module.login(LoginRequest(email="ghost@pdn.ac.lk", password="anything"))
    assert token is None


def test_list_users_returns_all_registered_users() -> None:
    module = _module()
    users = module.list_users()
    assert len(users) == 2


def test_issue_token_for_existing_email_returns_token() -> None:
    module = _module()
    token = module.issue_token("user@pdn.ac.lk")
    assert token is not None
    user = module.verify_token(token.access_token)
    assert user is not None
    assert user.email == "user@pdn.ac.lk"
    assert user.role == Role.USER


def test_verify_token_returns_correct_user() -> None:
    module = _module()
    token = module.issue_token("md@pdn.ac.lk")
    user = module.verify_token(token.access_token)
    assert user is not None
    assert user.email == "md@pdn.ac.lk"
    assert user.role == Role.MD


def test_register_without_password_still_gets_token_via_issue() -> None:
    module = LocalAuthorizationModule()
    user = module.register(RegisterRequest(email="new@pdn.ac.lk", role=Role.USER))
    assert user.email == "new@pdn.ac.lk"
    # Without password, login() fails, but issue_token() (admin flow) works.
    assert module.login(LoginRequest(email="new@pdn.ac.lk", password="")) is None
    token = module.issue_token("new@pdn.ac.lk")
    assert token is not None
    assert module.verify_token(token.access_token) is not None
