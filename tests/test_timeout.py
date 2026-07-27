"""Timeout / size-limit non-functional tests.

Exercises request size limits, response time expectations, and edge cases
that could lead to hangs or resource exhaustion.
"""

import time

import pytest


class TestRequestSizeLimits:
    """The API must reject oversized payloads gracefully."""

    def test_large_document_is_rejected(self, client, admin_headers, seed_application):
        client.post("/api/vault/unlock", json={"master_key": "master-key"}, headers=admin_headers)
        app_id = seed_application["id"]
        oversized = b"A" * (11 * 1024 * 1024)  # 11 MB > 10 MB limit
        resp = client.post(
            f"/api/applications/{app_id}/documents?filename=large.pdf",
            content=oversized,
            headers=admin_headers,
        )
        assert resp.status_code == 413

    def test_document_at_size_limit_is_accepted(self, client, admin_headers, seed_application):
        client.post("/api/vault/unlock", json={"master_key": "master-key"}, headers=admin_headers)
        app_id = seed_application["id"]
        at_limit = b"B" * (10 * 1024 * 1024)  # Exactly 10 MB
        resp = client.post(
            f"/api/applications/{app_id}/documents?filename=at_limit.pdf",
            content=at_limit,
            headers=admin_headers,
        )
        assert resp.status_code == 200

    def test_empty_document_is_accepted(self, client, admin_headers, seed_application):
        client.post("/api/vault/unlock", json={"master_key": "master-key"}, headers=admin_headers)
        app_id = seed_application["id"]
        resp = client.post(
            f"/api/applications/{app_id}/documents?filename=empty.txt",
            content=b"",
            headers=admin_headers,
        )
        assert resp.status_code == 200
        assert resp.json()["file_size"] == 0

    def test_invalid_filename_extension_is_rejected(self, client, admin_headers, seed_application):
        client.post("/api/vault/unlock", json={"master_key": "master-key"}, headers=admin_headers)
        app_id = seed_application["id"]
        resp = client.post(
            f"/api/applications/{app_id}/documents?filename=hack.exe",
            content=b"malicious",
            headers=admin_headers,
        )
        assert resp.status_code == 422


class TestResponseTime:
    """Verify endpoints respond within acceptable time bounds."""

    MAX_MS = 500

    def _timed_get(self, client, url, headers):
        start = time.perf_counter()
        resp = client.get(url, headers=headers)
        elapsed_ms = (time.perf_counter() - start) * 1000
        return resp.status_code, elapsed_ms

    def _timed_post(self, client, url, headers, json=None, content=None):
        start = time.perf_counter()
        if json is not None:
            resp = client.post(url, json=json, headers=headers)
        elif content is not None:
            resp = client.post(url, content=content, headers=headers)
        else:
            resp = client.post(url, headers=headers)
        elapsed_ms = (time.perf_counter() - start) * 1000
        return resp.status_code, elapsed_ms

    def test_list_applications_under_500ms(self, client, admin_headers, seed_application):
        _, elapsed = self._timed_get(client, "/api/applications", admin_headers)
        assert elapsed < self.MAX_MS, f"Slow: {elapsed:.1f}ms"

    def test_create_application_under_500ms(self, client, admin_headers, disclosure_payload):
        _, elapsed = self._timed_post(
            client, "/api/applications", admin_headers, json=disclosure_payload
        )
        assert elapsed < self.MAX_MS, f"Slow: {elapsed:.1f}ms"

    def test_vault_status_under_500ms(self, client, admin_headers):
        _, elapsed = self._timed_get(client, "/api/vault/status", admin_headers)
        assert elapsed < self.MAX_MS, f"Slow: {elapsed:.1f}ms"

    def test_unlock_vault_under_500ms(self, client, admin_headers):
        _, elapsed = self._timed_post(
            client, "/api/vault/unlock", admin_headers, json={"master_key": "master-key"}
        )
        assert elapsed < self.MAX_MS, f"Slow: {elapsed:.1f}ms"

    def test_list_documents_under_500ms(self, client, admin_headers, seed_application):
        client.post("/api/vault/unlock", json={"master_key": "master-key"}, headers=admin_headers)
        app_id = seed_application["id"]
        _, elapsed = self._timed_get(
            client, f"/api/applications/{app_id}/documents", admin_headers
        )
        assert elapsed < self.MAX_MS, f"Slow: {elapsed:.1f}ms"

    def test_change_status_under_500ms(self, client, admin_headers, seed_application):
        app_id = seed_application["id"]
        _, elapsed = self._timed_post(
            client, f"/api/applications/{app_id}/status?new_status=accepted",
            admin_headers,
        )
        assert elapsed < self.MAX_MS, f"Slow: {elapsed:.1f}ms"

    def test_notifications_under_500ms(self, client, admin_headers, seed_application):
        _, elapsed = self._timed_get(client, "/api/notifications", admin_headers)
        assert elapsed < self.MAX_MS, f"Slow: {elapsed:.1f}ms"

    def test_login_under_500ms(self, client):
        _, elapsed = self._timed_post(
            client, "/api/auth/login", {},
            json={"email": "admin@peradeniya.lk", "password": "pw"},
        )
        assert elapsed < self.MAX_MS, f"Slow: {elapsed:.1f}ms"
