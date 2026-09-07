"""Edge cases: institution-mail gate, duplicates, tokens, registration privileges."""

import pytest
from pydantic import ValidationError

from app.core.email_policy import email_domain, is_institution_email, normalize_email
from app.domain.common import Role
from app.modules.authorization.local import LocalAuthorizationModule
from app.modules.authorization.models import LoginRequest, RegisterRequest
from tests.conftest import auth_headers


@pytest.mark.parametrize(
    ("email", "expected"),
    [
        ("a@pdn.ac.lk", True),
        ("  a@pdn.ac.lk  ", True),  # surrounding whitespace tolerated
        ("A@PDN.AC.LK", True),  # case-insensitive
        ("u@sci.pdn.ac.lk", True),  # subdomain accepted
        ("u@deep.sci.pdn.ac.lk", True),  # nested subdomain accepted
        ("u@pdn.ac.lk.evil.com", False),  # lookalike: allowed domain is a prefix
        ("u@fakepdn.ac.lk", False),  # lookalike: suffix without dot boundary
        ("u@notpdn.ac.lk", False),
        ("u@gmail.com", False),
        ("not-an-email", False),
        ("", False),
        ("@pdn.ac.lk", False),  # empty local part
        ("a@@pdn.ac.lk", False),  # double @
        ("a @pdn.ac.lk", False),  # inner whitespace
        ("a@ pdn.ac.lk", False),
        ("a+b@pdn.ac.lk", True),  # plus-addressing is a single mailbox
    ],
)
def test_domain_matrix(email: str, expected: bool) -> None:
    assert is_institution_email(email) is expected


def test_normalize_and_domain_helpers() -> None:
    assert normalize_email("  A@PDN.AC.LK ") == "a@pdn.ac.lk"
    assert email_domain("A@Sci.Pdn.Ac.Lk") == "sci.pdn.ac.lk"
    assert email_domain("no-at-sign") == ""


def test_model_trims_and_lowercases() -> None:
    req = RegisterRequest(email="  A@PDN.AC.LK ", role=Role.USER)
    assert req.email == "a@pdn.ac.lk"


def test_duplicate_is_case_insensitive() -> None:
    module = LocalAuthorizationModule()
    module.register(RegisterRequest(email="md@pdn.ac.lk", role=Role.MD))
    with pytest.raises(ValueError, match="already registered"):
        module.register(RegisterRequest(email="MD@PDN.AC.LK", role=Role.MD))


def test_duplicate_over_http_is_409(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    r = c.post(
        "/auth/register",
        json={"email": "MD@PDN.AC.LK", "role": "user"},
        headers=auth_headers(tokens["md"]),
    )
    assert r.status_code == 409


def test_login_unregistered_institution_mail_is_401(seeded) -> None:
    c = seeded["client"]
    r = c.post("/auth/login", json={"email": "nobody@pdn.ac.lk", "password": "x"})
    assert r.status_code == 401


def test_login_wrong_password_is_401(seeded) -> None:
    c = seeded["client"]
    r = c.post("/auth/login", json={"email": "user-a@pdn.ac.lk", "password": "nope"})
    assert r.status_code == 401


@pytest.mark.parametrize("header", ["", "Bearer", "Bearer ", "Token abc", "bearer abc"])
def test_malformed_authorization_headers_rejected(seeded, header: str) -> None:
    c = seeded["client"]
    headers = {"Authorization": header} if header else {}
    assert c.get("/auth/me", headers=headers).status_code == 401


def test_director_cannot_create_staff_roles(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    r = c.post(
        "/auth/register",
        json={"email": "new-director@pdn.ac.lk", "role": "director"},
        headers=auth_headers(tokens["director"]),
    )
    assert r.status_code == 403


def test_bootstrap_rejects_foreign_admin_mail() -> None:
    auth = LocalAuthorizationModule()
    # Call with foreign bootstrap email via monkeypatched settings.
    import app.main as main_mod

    orig = main_mod.settings.bootstrap_admin_email
    orig_pw = main_mod.settings.bootstrap_admin_password
    try:
        main_mod.settings.bootstrap_admin_email = "boss@gmail.com"
        main_mod.settings.bootstrap_admin_password = "pw"
        with pytest.raises(ValueError, match="institution"):
            main_mod._bootstrap_admin(auth)
    finally:
        main_mod.settings.bootstrap_admin_email = orig
        main_mod.settings.bootstrap_admin_password = orig_pw


def test_login_request_model_rejects_foreign_mail() -> None:
    with pytest.raises(ValidationError):
        LoginRequest(email="a@gmail.com", password="x")
