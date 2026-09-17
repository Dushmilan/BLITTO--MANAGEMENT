"""TDD RED: non-critical application field updates + history read (issue #27)."""

from app.domain.common import ApplicationStatus
from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.application_intake.models import Disclosure


def _module():
    module = LocalApplicationIntakeModule()
    app = module.submit_disclosure(
        Disclosure(
            title="Old Title",
            inventor_name="A",
            inventor_email="a@pdn.ac.lk",
            summary="s",
            technology_area="Old Tech",
        )
    )
    return module, app


def test_update_application_fields() -> None:
    module, app = _module()
    updated = module.update_application_fields(
        app.id, title="New Title", technology_area="New Tech"
    )
    assert updated is not None
    assert updated.title == "New Title"
    assert updated.technology_area == "New Tech"
    assert updated.updated_at >= app.created_at
    # Status and identity are untouched by a field edit.
    assert updated.status == ApplicationStatus.DRAFT
    assert updated.id == app.id


def test_update_unknown_application_returns_none() -> None:
    module, _ = _module()
    assert module.update_application_fields("missing", title="X") is None


def test_status_history_readable_in_order() -> None:
    module, app = _module()
    module.change_status(app.id, ApplicationStatus.FILED, changed_by="md@pdn.ac.lk")
    history = module.get_status_history(app.id)
    assert [h.new_status for h in history] == [ApplicationStatus.FILED]
    assert history[0].changed_by == "md@pdn.ac.lk"


def test_status_history_empty_for_unknown() -> None:
    module, _ = _module()
    assert module.get_status_history("missing") == []


def test_patch_application_route(client, md_headers, seed_application) -> None:
    app_id = seed_application["id"]
    r = client.patch(
        f"/applications/{app_id}",
        json={"title": "Renamed", "technology_area": "Bio"},
        headers=md_headers,
    )
    assert r.status_code == 200, r.text[:300]
    assert r.json()["title"] == "Renamed"
    assert r.json()["technology_area"] == "Bio"

    r = client.patch(
        f"/applications/{app_id}", json={"status": "granted"}, headers=md_headers
    )
    assert r.status_code == 422

    r = client.patch(
        "/applications/missing", json={"title": "X"}, headers=md_headers
    )
    assert r.status_code == 404


def test_history_route(client, md_headers, seed_application) -> None:
    app_id = seed_application["id"]
    client.post(
        f"/applications/{app_id}/status",
        params={"new_status": "FILED"},
        headers=md_headers,
    )
    r = client.get(f"/applications/{app_id}/history", headers=md_headers)
    assert r.status_code == 200, r.text[:300]
    assert [h["new_status"] for h in r.json()] == ["FILED"]

    r = client.get("/applications/missing/history", headers=md_headers)
    assert r.status_code == 404
