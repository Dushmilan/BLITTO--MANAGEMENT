"""Docketing module-interface tests (local in-memory adapter)."""

from datetime import datetime, timezone

from app.modules.docketing.local import LocalDocketingModule
from app.modules.docketing.models import DeadlineStatus, DeadlineType


def _module() -> LocalDocketingModule:
    return LocalDocketingModule()


def test_add_deadline_returns_deadline_with_given_properties() -> None:
    module = _module()
    due = datetime(2026, 12, 31, tzinfo=timezone.utc)
    deadline = module.add_deadline("app-1", DeadlineType.FILING, due)

    assert deadline.id
    assert deadline.application_id == "app-1"
    assert deadline.type == DeadlineType.FILING
    assert deadline.due_date == due
    assert deadline.status == DeadlineStatus.OPEN


def test_list_deadlines_returns_all_when_no_filter() -> None:
    module = _module()
    d1 = module.add_deadline("app-1", DeadlineType.FILING, datetime(2026, 1, 1, tzinfo=timezone.utc))
    d2 = module.add_deadline("app-2", DeadlineType.RESPONSE, datetime(2026, 6, 1, tzinfo=timezone.utc))
    all_deadlines = module.list_deadlines()

    assert len(all_deadlines) == 2
    assert d1.id in [d.id for d in all_deadlines]
    assert d2.id in [d.id for d in all_deadlines]


def test_list_deadlines_filters_by_application() -> None:
    module = _module()
    module.add_deadline("app-1", DeadlineType.FILING, datetime(2026, 1, 1, tzinfo=timezone.utc))
    module.add_deadline("app-2", DeadlineType.RESPONSE, datetime(2026, 6, 1, tzinfo=timezone.utc))

    app1 = module.list_deadlines("app-1")
    assert len(app1) == 1
    assert app1[0].application_id == "app-1"


def test_list_deadlines_empty_when_none_added() -> None:
    module = _module()
    assert module.list_deadlines() == []
    assert module.list_deadlines("app-1") == []


def test_mark_met_changes_status_to_met() -> None:
    module = _module()
    deadline = module.add_deadline("app-1", DeadlineType.FILING, datetime(2026, 1, 1, tzinfo=timezone.utc))
    updated = module.mark_met(deadline.id)

    assert updated is not None
    assert updated.status == DeadlineStatus.MET
    assert updated.id == deadline.id


def test_mark_missed_changes_status_to_missed() -> None:
    module = _module()
    deadline = module.add_deadline("app-1", DeadlineType.FILING, datetime(2026, 1, 1, tzinfo=timezone.utc))
    updated = module.mark_missed(deadline.id)

    assert updated is not None
    assert updated.status == DeadlineStatus.MISSED
    assert updated.id == deadline.id


def test_mark_met_nonexistent_deadline_returns_none() -> None:
    module = _module()
    assert module.mark_met("nonexistent") is None


def test_mark_missed_nonexistent_deadline_returns_none() -> None:
    module = _module()
    assert module.mark_missed("nonexistent") is None


def test_add_deadline_defaults_to_open() -> None:
    module = _module()
    deadline = module.add_deadline("app-1", DeadlineType.MAINTENANCE_FEE, datetime(2026, 1, 1, tzinfo=timezone.utc))
    assert deadline.status == DeadlineStatus.OPEN


def test_multiple_deadlines_on_same_application() -> None:
    module = _module()
    d1 = module.add_deadline("app-1", DeadlineType.FILING, datetime(2026, 1, 1, tzinfo=timezone.utc))
    d2 = module.add_deadline("app-1", DeadlineType.RESPONSE, datetime(2026, 6, 1, tzinfo=timezone.utc))
    d3 = module.add_deadline("app-1", DeadlineType.MAINTENANCE_FEE, datetime(2027, 1, 1, tzinfo=timezone.utc))

    app_deadlines = module.list_deadlines("app-1")
    assert len(app_deadlines) == 3
    assert all(d.application_id == "app-1" for d in app_deadlines)


def test_all_deadline_types_accepted() -> None:
    module = _module()
    for dtype in DeadlineType:
        deadline = module.add_deadline("app-1", dtype, datetime(2026, 1, 1, tzinfo=timezone.utc))
        assert deadline.type == dtype


def test_list_deadlines_returns_different_objects_after_mutation() -> None:
    module = _module()
    deadline = module.add_deadline("app-1", DeadlineType.FILING, datetime(2026, 1, 1, tzinfo=timezone.utc))
    module.mark_met(deadline.id)
    listed = module.list_deadlines("app-1")
    assert len(listed) == 1
    assert listed[0].status == DeadlineStatus.MET
