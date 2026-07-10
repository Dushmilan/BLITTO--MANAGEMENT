"""Audit module-interface tests (local adapter)."""

from datetime import datetime, timedelta, timezone

from app.modules.audit.local import LocalAuditModule


def test_by_actor_returns_entries_for_user() -> None:
    module = LocalAuditModule()
    module.record("action1", user_id="user1")
    module.record("action2", user_id="user2")
    module.record("action3", user_id="user1")
    results = module.by_actor("user1")
    assert len(results) == 2
    assert all(e.user_id == "user1" for e in results)


def test_by_action_returns_entries_with_action() -> None:
    module = LocalAuditModule()
    module.record("create", user_id="u1")
    module.record("update", user_id="u2")
    module.record("create", user_id="u3")
    results = module.by_action("create")
    assert len(results) == 2
    assert all(e.action == "create" for e in results)


def test_by_date_range_returns_entries_in_range() -> None:
    module = LocalAuditModule()
    now = datetime.now(timezone.utc)
    entry1 = module.record("action1", user_id="u1")
    entry1.timestamp = now  # ensure within range
    # Create an entry with timestamp in the past
    entry2 = module.record("action2", user_id="u2")
    entry2.timestamp = now - timedelta(days=10)
    # Query last 5 days
    start = now - timedelta(days=5)
    end = now
    results = module.by_date_range(start, end)
    assert len(results) == 1
    assert results[0].action == "action1"

