"""Load / concurrency non-functional tests.

Uses concurrent HTTP requests via ThreadPoolExecutor to exercise the
in-memory backend under contention.
"""

from concurrent.futures import ThreadPoolExecutor, as_completed

import pytest


class TestConcurrentLoad:
    """Run N concurrent operations against the API."""

    CONCURRENCY = 8
    VALID_STATUSES = ["FILED", "PUBLISHED", "EXAMINATION", "ACKNOWLEDGED", "GRANTED", "REJECTED"]

    @pytest.fixture(autouse=True)
    def _seed_staff(self, client):
        auth = client.app.state.authorization
        from app.domain.common import Role
        from app.modules.authorization.models import RegisterRequest
        for i in range(self.CONCURRENCY):
            email = f"concurrent_staff{i}@peradeniya.lk"
            if not any(u.email == email for u in auth._users.values()):
                auth.register(
                    RegisterRequest(email=email, role=Role.ATTORNEY, password="pw")
                )

    def _headers(self, client, email):
        from app.modules.authorization.models import LoginRequest
        token = client.app.state.authorization.login(
            LoginRequest(email=email, password="pw")
        )
        return {"Authorization": f"Bearer {token.access_token}"}

    def test_concurrent_create_applications(self, client):
        """All concurrent POST /api/applications succeed."""
        disclosure = {
            "inventors": [{"inventor_name": "Staff User", "inventor_email": "concurrent_staff0@peradeniya.lk"}],
            "title": "Concurrent Patent",
            "summary": "Testing concurrent application creation.",
        }
        headers_list = [self._headers(client, f"concurrent_staff{i}@peradeniya.lk") for i in range(self.CONCURRENCY)]

        def post_app(i):
            h = {**headers_list[i], "Content-Type": "application/json"}
            resp = client.post("/api/applications", json=disclosure, headers=h)
            return resp.status_code

        with ThreadPoolExecutor(max_workers=self.CONCURRENCY) as pool:
            fut = [pool.submit(post_app, i) for i in range(self.CONCURRENCY)]
            results = [f.result() for f in as_completed(fut)]

        assert all(s == 200 for s in results), f"Not all succeeded: {results}"

    def test_concurrent_read_applications(self, client, admin_headers, seed_application):
        """Concurrent GET /api/applications all return 200."""

        def get_apps(_):
            resp = client.get("/api/applications", headers=admin_headers)
            return resp.status_code

        with ThreadPoolExecutor(max_workers=self.CONCURRENCY) as pool:
            fut = [pool.submit(get_apps, i) for i in range(self.CONCURRENCY)]
            results = [f.result() for f in as_completed(fut)]

        assert all(s == 200 for s in results), f"Not all succeeded: {results}"

    def test_concurrent_document_upload(self, client, admin_headers, seed_application):
        """Concurrent uploads to the same application all succeed."""
        client.post("/api/vault/unlock", json={"master_key": "master-key"}, headers=admin_headers)
        app_id = seed_application["id"]

        def upload(i):
            resp = client.post(
                f"/api/applications/{app_id}/documents?filename=concurrent_{i}.pdf",
                content=b"%PDF concurrent " + str(i).encode(),
                headers=admin_headers,
            )
            return (i, resp.status_code, resp.json().get("id"))

        with ThreadPoolExecutor(max_workers=self.CONCURRENCY) as pool:
            fut = [pool.submit(upload, i) for i in range(self.CONCURRENCY)]
            results = [f.result() for f in as_completed(fut)]

        codes = [r[1] for r in results]
        assert all(s == 200 for s in codes), f"Not all uploads succeeded: {codes}"
        ids = [r[2] for r in results]
        assert len(set(ids)) == self.CONCURRENCY, "Duplicate document IDs"

    def test_concurrent_status_change(self, client, admin_headers, seed_application):
        """Concurrent status changes from different threads, each a valid transition."""
        app_id = seed_application["id"]

        def change_status(new_status):
            resp = client.post(
                f"/api/applications/{app_id}/status?new_status={new_status}",
                headers=admin_headers,
            )
            return resp.status_code

        with ThreadPoolExecutor(max_workers=4) as pool:
            fut = [pool.submit(change_status, s) for s in self.VALID_STATUSES[:4]]
            results = [f.result() for f in as_completed(fut)]

        assert all(s == 200 for s in results), f"Not all succeeded: {results}"
