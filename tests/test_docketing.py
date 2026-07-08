"""Docketing module-interface tests (local adapter)."""

from datetime import datetime

from app.modules.docketing.local import LocalDocketingModule
from app.modules.docketing.models import DeadlineType


def test_add_and_list_deadline() -> None:
    module = LocalDocketingModule()
    d = module.add_deadline("app-1", DeadlineType.RESPONSE, datetime(2026, 1, 1))
    assert d.application_id == "app-1"
    assert len(module.list_deadlines("app-1")) == 1
    assert module.list_deadlines() == module.list_deadlines("app-1")


def test_mark_met_and_missed() -> None:
    module = LocalDocketingModule()
    d = module.add_deadline("app-1", DeadlineType.RESPONSE, datetime(2026, 1, 1))
    assert module.mark_met(d.id).status.value == "MET"
    assert module.mark_missed(d.id).status.value == "MISSED"
    assert module.mark_met("nope") is None
