"""TDD RED: institution-mail-only gate."""

import pytest
from pydantic import ValidationError

from app.core.email_policy import is_institution_email
from app.domain.common import Role
from app.modules.authorization.local import LocalAuthorizationModule
from app.modules.authorization.models import LoginRequest, RegisterRequest


def test_external_mail_rejected_at_model() -> None:
    with pytest.raises(ValidationError):
        RegisterRequest(email="someone@gmail.com", role=Role.USER)
    with pytest.raises(ValidationError):
        LoginRequest(email="someone@gmail.com", password="x")


def test_institution_mail_accepted_case_insensitive_and_subdomain() -> None:
    req = RegisterRequest(email="A.B@SCI.PDN.AC.LK", role=Role.USER)
    assert req.email == "a.b@sci.pdn.ac.lk"
    assert is_institution_email("User@Pdn.Ac.Lk")
    assert is_institution_email("u@sci.pdn.ac.lk")
    assert not is_institution_email("u@gmail.com")
    assert not is_institution_email("not-an-email")


def test_register_rejects_non_institution_even_if_validator_bypassed() -> None:
    module = LocalAuthorizationModule()
    bypassed = RegisterRequest.model_construct(
        email="x@gmail.com", role=Role.USER, user_code=None, password="pw"
    )
    with pytest.raises(ValueError, match="Institution"):
        module.register(bypassed)


def test_login_blocks_non_institution() -> None:
    module = LocalAuthorizationModule()
    module.register(RegisterRequest(email="a@pdn.ac.lk", role=Role.USER, password="pw"))
    bypassed = LoginRequest.model_construct(email="x@gmail.com", password="pw")
    assert module.login(bypassed) is None


def test_verify_locks_out_legacy_non_institution_user() -> None:
    from app.modules.authorization.models import User

    module = LocalAuthorizationModule()
    legacy = User(id="legacy-1", email="legacy@gmail.com", role=Role.USER)
    module._users[legacy.id] = legacy
    token = module._issue(legacy)
    assert module.verify_token(token.access_token) is None
