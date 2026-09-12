"""TDD: lifecycle transitions are enforced (no DRAFT->GRANTED jumps)."""

from __future__ import annotations

import pytest

from app.domain.common import ApplicationStatus
from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.application_intake.models import Disclosure


def _app(module: LocalApplicationIntakeModule):
    return module.submit_disclosure(
        Disclosure(
            title="Lifecycle",
            inventor_name="Life Cycle",
            inventor_email="life@pdn.ac.lk",
            summary="s",
        )
    )


def test_module_rejects_draft_to_granted() -> None:
    from app.modules.application_intake.local import InvalidTransitionError

    module = LocalApplicationIntakeModule()
    app = _app(module)
    with pytest.raises(InvalidTransitionError):
        module.change_status(app.id, ApplicationStatus.GRANTED, "md@pdn.ac.lk")
    assert module.get_application(app.id).status == ApplicationStatus.DRAFT


def test_module_allows_legal_chain_to_grant() -> None:
    module = LocalApplicationIntakeModule()
    app = _app(module)
    for status in (
        ApplicationStatus.FILED,
        ApplicationStatus.ACKNOWLEDGED,
        ApplicationStatus.EXAMINATION,
        ApplicationStatus.GRANTED,
    ):
        updated = module.change_status(app.id, status, "md@pdn.ac.lk")
        assert updated is not None
        assert updated.status == status


def test_api_rejects_draft_to_granted_with_422(client, md_headers, disclosure_payload) -> None:
    app_id = client.post("/applications", json=disclosure_payload, headers=md_headers).json()["id"]
    r = client.post(
        f"/applications/{app_id}/status",
        params={"new_status": "GRANTED"},
        headers=md_headers,
    )
    assert r.status_code == 422
