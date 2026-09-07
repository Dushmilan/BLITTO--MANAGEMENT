"""Application lifecycle + auth token-expiry tests (module-interface level)."""

import time

from app.domain.common import ApplicationStatus, Role
from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.application_intake.models import Disclosure
from app.modules.authorization.local import LocalAuthorizationModule
from app.modules.authorization.models import RegisterRequest


def _disclosure() -> Disclosure:
    return Disclosure(inventor_name="A", inventor_email="a@pdn.ac.lk", title="X", summary="s")


def test_change_status_records_history() -> None:
    intake = LocalApplicationIntakeModule()
    app = intake.create_application_shell(_disclosure())
    updated = intake.change_status(app.id, ApplicationStatus.EXAMINATION, "md@pdn.ac.lk")
    assert updated is not None
    assert updated.status == ApplicationStatus.EXAMINATION
    # Unknown application returns None.
    assert intake.change_status("nope", ApplicationStatus.GRANTED, "md") is None


def test_token_expires() -> None:
    module = LocalAuthorizationModule()
    module.register(RegisterRequest(email="a@pdn.ac.lk", role=Role.USER))
    token = module.issue_token("a@pdn.ac.lk")
    assert module.verify_token(token.access_token) is not None
    # A token issued in the past (exp already passed) must be rejected.
    import jwt
    from app.core.config import settings

    expired = jwt.encode(
        {"sub": "x", "email": "a@pdn.ac.lk", "role": "user",
         "exp": int(time.time()) - 10},
        settings.auth_secret,
        algorithm="HS256",
    )
    assert module.verify_token(expired) is None
