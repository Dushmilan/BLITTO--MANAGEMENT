"""Application Intake extended module-interface tests (local adapter)."""

from app.domain.common import ApplicationStatus
from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.application_intake.models import Disclosure, Inventor


def _module() -> LocalApplicationIntakeModule:
    return LocalApplicationIntakeModule()


def _disclosure(title: str = "Test Patent", inventor_email: str = "inv@pdn.ac.lk") -> Disclosure:
    return Disclosure(
        title=title,
        inventors=[Inventor(inventor_name="A. Inventor", inventor_email=inventor_email)],
        summary="A test disclosure.",
    )


def test_list_applications_empty_when_none_created() -> None:
    module = _module()
    assert module.list_applications() == []


def test_get_application_by_id_returns_correct_app() -> None:
    module = _module()
    app = module.create_application_shell(_disclosure("Alpha"))
    fetched = module.get_application(app.id)
    assert fetched is not None
    assert fetched.id == app.id
    assert fetched.title == "Alpha"


def test_get_nonexistent_application_returns_none() -> None:
    module = _module()
    assert module.get_application("nonexistent") is None


def test_get_applications_for_inventor_returns_matching() -> None:
    module = _module()
    a1 = module.create_application_shell(_disclosure("Alpha", "a@pdn.ac.lk"))
    module.create_application_shell(_disclosure("Beta", "b@pdn.ac.lk"))

    results = module.get_applications_for_inventor("a@pdn.ac.lk")
    assert len(results) == 1
    assert results[0].id == a1.id


def test_get_applications_for_inventor_empty_when_no_match() -> None:
    module = _module()
    module.create_application_shell(_disclosure("Alpha", "a@pdn.ac.lk"))
    results = module.get_applications_for_inventor("ghost@pdn.ac.lk")
    assert results == []


def test_change_status_to_valid_state() -> None:
    module = _module()
    app = module.create_application_shell(_disclosure())
    module.change_status(app.id, ApplicationStatus.FILED, "md@pdn.ac.lk")
    updated = module.change_status(app.id, ApplicationStatus.EXAMINATION, "md@pdn.ac.lk")
    assert updated is not None
    assert updated.status == ApplicationStatus.EXAMINATION


def test_change_status_nonexistent_app_returns_none() -> None:
    module = _module()
    assert module.change_status("nope", ApplicationStatus.GRANTED, "admin") is None


def test_get_inventors_for_application() -> None:
    module = _module()
    app = module.create_application_shell(_disclosure())
    inventors = module.get_inventors_for_application(app.id)
    assert len(inventors) == 1
    assert inventors[0].inventor_email == "inv@pdn.ac.lk"


def test_get_inventors_for_nonexistent_app_returns_empty() -> None:
    module = _module()
    assert module.get_inventors_for_application("nope") == []


def test_multiple_inventors_on_one_application() -> None:
    module = _module()
    disclosure = Disclosure(
        title="Joint Patent",
        inventors=[
            Inventor(inventor_name="Alice", inventor_email="alice@pdn.ac.lk"),
            Inventor(inventor_name="Bob", inventor_email="bob@pdn.ac.lk"),
        ],
        summary="Joint work.",
    )
    app = module.create_application_shell(disclosure)
    inventors = module.get_inventors_for_application(app.id)
    assert len(inventors) == 2
    assert inventors[0].inventor_email == "alice@pdn.ac.lk"


def test_create_application_shell_creates_with_draft_status() -> None:
    module = _module()
    app = module.create_application_shell(_disclosure())
    assert app.status == ApplicationStatus.DRAFT
