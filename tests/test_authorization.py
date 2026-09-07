"""Authorization module-interface tests (local stub adapter)."""

import pytest

from app.modules.authorization.local import LocalAuthorizationModule
from app.modules.authorization.models import RegisterRequest
from app.domain.common import Role


def _module() -> LocalAuthorizationModule:
    module = LocalAuthorizationModule()
    module.register(RegisterRequest(email="md@pdn.ac.lk", role=Role.MD))
    return module


def test_register_then_issue_and_verify_token() -> None:
    module = _module()
    token = module.issue_token("md@pdn.ac.lk")
    assert token is not None
    user = module.verify_token(token.access_token)  # type: ignore[union-attr]
    assert user is not None
    assert user.email == "md@pdn.ac.lk"
    assert user.role == Role.MD


def test_verify_invalid_token_returns_none() -> None:
    module = _module()
    assert module.verify_token("not-a-real-token") is None


def test_issue_token_unknown_email_returns_none() -> None:
    module = _module()
    assert module.issue_token("ghost@pdn.ac.lk") is None
