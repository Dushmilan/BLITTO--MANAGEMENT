"""TDD RED: single main-author rule (multi-author retracted)."""

import pytest
from pydantic import ValidationError

from app.modules.application_intake.models import Disclosure


def _base(**overrides):
    data = {
        "inventor_name": "A. Inventor",
        "inventor_email": "a@pdn.ac.lk",
        "title": "X",
        "summary": "s",
    }
    data.update(overrides)
    return data


def test_single_author_accepted() -> None:
    d = Disclosure(**_base())
    assert d.inventor_email == "a@pdn.ac.lk"


def test_multi_author_email_list_rejected() -> None:
    with pytest.raises(ValidationError):
        Disclosure(**_base(inventor_email="a@pdn.ac.lk,b@pdn.ac.lk"))
    with pytest.raises(ValidationError):
        Disclosure(**_base(inventor_email="a@pdn.ac.lk; b@pdn.ac.lk"))


def test_multi_author_name_rejected() -> None:
    with pytest.raises(ValidationError):
        Disclosure(**_base(inventor_name="A, B"))


def test_non_institution_author_rejected() -> None:
    with pytest.raises(ValidationError):
        Disclosure(**_base(inventor_email="a@gmail.com"))
