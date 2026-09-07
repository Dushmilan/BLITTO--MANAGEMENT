"""HTTP-level audit tests — verify every mutating API route records an audit entry."""

import pytest


class TestAuditApiSideEffects:
    """Each mutating endpoint must produce exactly one audit log entry."""

    @staticmethod
    def _next_valid_status(current: str) -> str:
        """Return a valid status transition from current."""
        order = ["DRAFT", "FILED", "PUBLISHED", "EXAMINATION", "ACKNOWLEDGED", "GRANTED", "REJECTED", "MAINTENANCE"]
        try:
            idx = order.index(current)
            return order[idx + 1] if idx + 1 < len(order) else order[-1]
        except (ValueError, IndexError):
            return "FILED"

    def test_create_application_records_audit(self, client, md_headers, disclosure_payload):
        audit = client.app.state.audit
        before = len(audit._log)
        resp = client.post("/api/applications", json=disclosure_payload, headers=md_headers)
        assert resp.status_code == 200
        after = len(audit._log)
        assert after == before + 1
        latest = list(audit._log.values())[-1]
        assert latest.action == "create_application"
        assert latest.application_id == resp.json()["id"]

    def test_store_document_records_audit(self, client, md_headers, disclosure_payload, seed_application):
        # Unlock vault first
        client.post("/api/vault/unlock", json={"master_key": "master-key"}, headers=md_headers)
        app_id = seed_application["id"]
        audit = client.app.state.audit
        before = len(audit._log)
        resp = client.post(
            f"/api/applications/{app_id}/documents?filename=test.pdf",
            content=b"%PDF-1.4 mock content",
            headers=md_headers,
        )
        assert resp.status_code == 200
        after = len(audit._log)
        assert after == before + 1
        latest = list(audit._log.values())[-1]
        assert latest.action == "store_document"
        assert latest.application_id == app_id

    def test_delete_document_records_audit(self, client, md_headers, seed_application):
        client.post("/api/vault/unlock", json={"master_key": "master-key"}, headers=md_headers)
        app_id = seed_application["id"]
        store = client.post(
            f"/api/applications/{app_id}/documents?filename=delete_me.txt",
            content=b"content",
            headers=md_headers,
        )
        doc_id = store.json()["id"]
        audit = client.app.state.audit
        before = len(audit._log)
        resp = client.delete(
            f"/api/applications/{app_id}/documents/{doc_id}",
            headers=md_headers,
        )
        assert resp.status_code == 204
        after = len(audit._log)
        assert after == before + 1
        latest = list(audit._log.values())[-1]
        assert latest.action == "delete_document"
        assert latest.application_id == app_id

    def test_change_status_records_audit(self, client, md_headers, seed_application):
        app_id = seed_application["id"]
        current = seed_application["status"]
        next_status = self._next_valid_status(current)
        audit = client.app.state.audit
        before = len(audit._log)
        resp = client.post(
            f"/api/applications/{app_id}/status?new_status={next_status}",
            headers=md_headers,
        )
        assert resp.status_code == 200, f"Failed {current} -> {next_status}: {resp.text}"
        after = len(audit._log)
        assert after == before + 1
        latest = list(audit._log.values())[-1]
        assert latest.action == f"status_change:{next_status}"
        assert latest.application_id == app_id

    def test_change_status_rejected_records_audit(self, client, md_headers, seed_application):
        app_id = seed_application["id"]
        audit = client.app.state.audit
        for s in ["FILED", "PUBLISHED", "EXAMINATION", "ACKNOWLEDGED"]:
            resp = client.post(
                f"/api/applications/{app_id}/status?new_status={s}",
                headers=md_headers,
            )
            if resp.status_code == 200:
                break
        before = len(audit._log)
        resp = client.post(
            f"/api/applications/{app_id}/status?new_status=REJECTED",
            headers=md_headers,
        )
        assert resp.status_code == 200, resp.text
        after = len(audit._log)
        assert after == before + 1
        latest = list(audit._log.values())[-1]
        assert latest.action == "status_change:REJECTED"

    def test_send_notification_records_audit(self, client, md_headers, seed_application):
        app_id = seed_application["id"]
        audit = client.app.state.audit
        before = len(audit._log)
        resp = client.post(
            f"/api/applications/{app_id}/notify?subject=Test&body=Hello",
            headers=md_headers,
        )
        assert resp.status_code == 200
        after = len(audit._log)
        assert after == before + 1
        latest = list(audit._log.values())[-1]
        assert latest.action == "send_notification"
        assert latest.application_id == app_id

    def test_non_mutating_route_does_not_record_audit(self, client, md_headers, seed_application):
        audit = client.app.state.audit
        before = len(audit._log)
        resp = client.get("/api/applications", headers=md_headers)
        assert resp.status_code == 200
        assert len(audit._log) == before

    def test_audit_entry_includes_user_id(self, client, md_headers, disclosure_payload):
        audit = client.app.state.audit
        resp = client.post("/api/applications", json=disclosure_payload, headers=md_headers)
        assert resp.status_code == 200
        latest = list(audit._log.values())[-1]
        assert latest.user_id is not None

    def test_for_application_returns_matching_entries(self, client, md_headers, disclosure_payload):
        resp = client.post("/api/applications", json=disclosure_payload, headers=md_headers)
        app_id = resp.json()["id"]
        audit = client.app.state.audit
        entries = audit.for_application(app_id)
        assert len(entries) == 1
        assert entries[0].application_id == app_id

    def test_by_actor_returns_user_entries(self, client, md_headers, disclosure_payload):
        client.post("/api/applications", json=disclosure_payload, headers=md_headers)
        audit = client.app.state.audit
        first = list(audit._log.values())[0]
        entries = audit.by_actor(first.user_id)
        assert len(entries) >= 1
        assert all(e.user_id == first.user_id for e in entries)
