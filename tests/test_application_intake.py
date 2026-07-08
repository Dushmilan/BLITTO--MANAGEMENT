"""Tests at module-interface level only (Rules.md: no unit tests on internals)."""

from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.application_intake.models import Disclosure


def test_submit_disclosure_creates_application_shell() -> None:
    module = LocalApplicationIntakeModule()
    disclosure = Disclosure(
        inventor_name="A. Inventor",
        inventor_email="a@uni.edu",
        title="Solar Widget",
        summary="A widget.",
    )
    application = module.submit_disclosure(disclosure)

    assert application.id
    assert application.title == "Solar Widget"
    assert application.status.value == "DRAFT"
    assert module.get_application(application.id) is not None
    assert len(module.list_applications()) == 1
