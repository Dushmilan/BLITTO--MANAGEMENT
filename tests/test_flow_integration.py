"""End-to-end flow integration tests — multi-step user journeys through the API."""

from app.domain.common import Role
from app.modules.authorization.models import RegisterRequest

MD_EMAIL = "flow-md@pdn.ac.lk"
MD_PW = "flowmdpw-123"


def _ensure_md(client):
    auth = client.app.state.authorization
    if not any(u.email == MD_EMAIL for u in auth._users.values()):
        auth.register(RegisterRequest(email=MD_EMAIL, role=Role.MD, password=MD_PW))


def _md_headers(client):
    _ensure_md(client)
    resp = client.post("/api/auth/login", json={
        "email": MD_EMAIL,
        "password": MD_PW,
    })
    assert resp.status_code == 200, resp.text[:300]
    return {"Authorization": f"Bearer {resp.json()['access_token']}"}


def _app_payload(title, name, email, summary=""):
    return {
        "title": title,
        "inventor_name": name,
        "inventor_email": email,
        "summary": summary,
    }


def _register_user(client, md_headers, email, password="secret"):
    reg = client.post("/api/auth/register", json={
        "email": email, "password": password, "role": "user",
    }, headers=md_headers)
    assert reg.status_code in (200, 201), reg.text


def _login(client, email, password="secret"):
    resp = client.post("/api/auth/login", json={"email": email, "password": password})
    assert resp.status_code == 200, resp.text[:300]
    return {"Authorization": f"Bearer {resp.json()['access_token']}"}


def test_happy_path_full_grant(client):
    """MD registers user → creates application → files → office action → grant."""
    md_hdrs = _md_headers(client)

    # 1. MD registers user
    _register_user(client, md_hdrs, "happy-flow@pdn.ac.lk")

    # 2. MD creates application (user cannot: StaffUser gate)
    app = client.post("/api/applications",
                      json=_app_payload("Flow Test Invention", "Flow Inventor",
                                        "happy-flow@pdn.ac.lk", "End-to-end flow test"),
                      headers=md_hdrs)
    assert app.status_code == 200, app.text
    app_id = app.json()["id"]

    # 3. MD files the application
    file_resp = client.post(f"/api/admin/filing/{app_id}/file", headers=md_hdrs)
    assert file_resp.status_code == 200, file_resp.text

    # 4. MD sends office action
    oa = client.post(f"/api/applications/{app_id}/office-actions",
                      params={"kind": "REJECTION", "body": "Need more details"},
                      headers=md_hdrs)
    assert oa.status_code == 200, oa.text

    # 4b. Move to EXAMINATION through the legal lifecycle before grant.
    exam = client.post(f"/api/applications/{app_id}/status",
                       params={"new_status": "FILED"},
                       headers=md_hdrs)
    # Already FILED via filing; FILED->FILED is an idempotent no-op.
    assert exam.status_code == 200, exam.text
    exam = client.post(f"/api/applications/{app_id}/status",
                       params={"new_status": "EXAMINATION"},
                       headers=md_hdrs)
    assert exam.status_code == 200, exam.text

    # 5. MD grants patent
    grant = client.post(f"/api/admin/filing/{app_id}/grant",
                         params={"patent_number": "LK/PAT/2026/999"},
                         headers=md_hdrs)
    assert grant.status_code == 200, grant.text

    # 6. Verify final status
    status = client.get(f"/api/admin/filing/{app_id}/status", headers=md_hdrs)
    assert status.status_code == 200
    assert status.json()["granted_at"] is not None
    assert status.json()["patent_number"] == "LK/PAT/2026/999"


def test_rejection_path(client):
    """Create → file → reject (no response)."""
    md_hdrs = _md_headers(client)

    app = client.post("/api/applications",
                      json=_app_payload("Rejection Flow Test", "Rejected",
                                        "rejected-flow@pdn.ac.lk", "Will be rejected"),
                      headers=md_hdrs)
    app_id = app.json()["id"]

    client.post(f"/api/admin/filing/{app_id}/file", headers=md_hdrs)

    reject = client.post(f"/api/admin/filing/{app_id}/reject", headers=md_hdrs)
    assert reject.status_code == 200, reject.text

    status = client.get(f"/api/admin/filing/{app_id}/status", headers=md_hdrs)
    assert status.json()["rejected_at"] is not None


def test_defect_sheet_flow(client):
    """Create → file → defect → acknowledge → grant."""
    md_hdrs = _md_headers(client)

    app = client.post("/api/applications",
                      json=_app_payload("Defect Flow Test", "Defect Tester",
                                        "defect-flow@pdn.ac.lk"),
                      headers=md_hdrs)
    app_id = app.json()["id"]

    client.post(f"/api/admin/filing/{app_id}/file", headers=md_hdrs)

    defect = client.post(
        f"/api/admin/filing/{app_id}/defect-sheets",
        params={"sheet_number": 1, "description": "Missing signature"},
        headers=md_hdrs,
    )
    assert defect.status_code == 200

    apps = client.get("/api/applications", headers=md_hdrs).json()
    assert any(a["status"] == "DEFECT_SHEET_1" for a in apps if a["id"] == app_id)

    sheets = client.get(f"/api/admin/filing/{app_id}/defect-sheets", headers=md_hdrs)
    assert sheets.status_code == 200
    assert len(sheets.json()) >= 1

    ack = client.post(f"/api/admin/filing/{app_id}/acknowledge", headers=md_hdrs)
    assert ack.status_code == 200

    grant = client.post(f"/api/admin/filing/{app_id}/grant",
                         params={"patent_number": "LK/PAT/2026/888"},
                         headers=md_hdrs)
    assert grant.status_code == 200


def test_notification_during_flow(client):
    """Notifications are sent at key milestones during the filing flow."""
    md_hdrs = _md_headers(client)
    _register_user(client, md_hdrs, "notif-flow@pdn.ac.lk")
    inv_hdrs = _login(client, "notif-flow@pdn.ac.lk")

    before = len(client.get("/api/notifications", headers=inv_hdrs).json())

    app = client.post("/api/applications",
                      json=_app_payload("Notification Flow Test", "Notif",
                                        "notif-flow@pdn.ac.lk"),
                      headers=md_hdrs)
    app_id = app.json()["id"]

    client.post(f"/api/admin/filing/{app_id}/file", headers=md_hdrs)

    after = client.get("/api/notifications", headers=inv_hdrs)
    assert len(after.json()) > before, "Expected new notification after filing"


def test_document_vault_integration(client):
    """Upload → lock → upload blocked → unlock → upload works."""
    md_hdrs = _md_headers(client)

    app = client.post("/api/applications",
                      json=_app_payload("Vault Integration Test", "Vault Tester",
                                        "vault-flow@pdn.ac.lk"),
                      headers=md_hdrs)
    app_id = app.json()["id"]

    up1 = client.post(f"/api/applications/{app_id}/documents?filename=doc1.pdf",
                       headers=md_hdrs, content=b"content")
    assert up1.status_code == 200

    client.post("/api/vault/lock", headers=md_hdrs)

    up2 = client.post(f"/api/applications/{app_id}/documents?filename=doc2.pdf",
                       headers=md_hdrs, content=b"more")
    assert up2.status_code == 423

    client.post("/api/vault/unlock", json={"master_key": "dummy-key"}, headers=md_hdrs)

    up3 = client.post(f"/api/applications/{app_id}/documents?filename=doc3.pdf",
                       headers=md_hdrs, content=b"more")
    assert up3.status_code == 200


def test_staff_can_manage_applications(client):
    """Staff can create, file, upload, and manage applications."""
    md_hdrs = _md_headers(client)

    app = client.post("/api/applications",
                      json=_app_payload("Staff-Managed App", "Alice",
                                        "alice-flow@pdn.ac.lk", "Managed by staff"),
                      headers=md_hdrs)
    assert app.status_code == 200
    app_id = app.json()["id"]

    file_resp = client.post(f"/api/admin/filing/{app_id}/file", headers=md_hdrs)
    assert file_resp.status_code == 200

    doc = client.post(f"/api/applications/{app_id}/documents?filename=staff.pdf",
                       headers=md_hdrs, content=b"staff content")
    assert doc.status_code == 200

    notify = client.post(
        f"/api/applications/{app_id}/notify",
        params={"subject": "Update", "body": "Your application is progressing"},
        headers=md_hdrs,
    )
    assert notify.status_code == 200


def test_user_sees_only_own_applications(client):
    """Users only see their own applications when listing."""
    md_hdrs = _md_headers(client)
    _register_user(client, md_hdrs, "iso-a@pdn.ac.lk")
    _register_user(client, md_hdrs, "iso-b@pdn.ac.lk")
    client.post("/api/applications",
                json=_app_payload("Iso A", "Iso A", "iso-a@pdn.ac.lk"),
                headers=md_hdrs)
    client.post("/api/applications",
                json=_app_payload("Iso B", "Iso B", "iso-b@pdn.ac.lk"),
                headers=md_hdrs)

    apps1 = {a["id"] for a in client.get(
        "/api/applications", headers=_login(client, "iso-a@pdn.ac.lk")).json()}
    apps2 = {a["id"] for a in client.get(
        "/api/applications", headers=_login(client, "iso-b@pdn.ac.lk")).json()}

    assert len(apps1) == 1 and len(apps2) == 1
    assert apps1.isdisjoint(apps2), "Users should not see each other's applications"


def test_user_can_see_own_notifications(client):
    """User sees only notifications addressed to them."""
    md_hdrs = _md_headers(client)
    _register_user(client, md_hdrs, "notif-own@pdn.ac.lk")
    inv_hdrs = _login(client, "notif-own@pdn.ac.lk")

    notifs = client.get("/api/notifications", headers=inv_hdrs)
    assert notifs.status_code == 200
    all_recipients = [n.get("recipient_email", "") for n in notifs.json()]
    if all_recipients:
        assert all(r == "notif-own@pdn.ac.lk" for r in all_recipients)


def test_parallel_flow_md_and_user_views(client):
    """MD creates apps for two users; each sees only their own."""
    md_hdrs = _md_headers(client)
    _register_user(client, md_hdrs, "parallel-a@pdn.ac.lk")
    _register_user(client, md_hdrs, "parallel-b@pdn.ac.lk")

    app_a = client.post("/api/applications",
                        json=_app_payload("Parallel App A", "Parallel A",
                                          "parallel-a@pdn.ac.lk"),
                        headers=md_hdrs)
    app_b = client.post("/api/applications",
                        json=_app_payload("Parallel App B", "Parallel B",
                                          "parallel-b@pdn.ac.lk"),
                        headers=md_hdrs)

    apps_a = client.get("/api/applications",
                        headers=_login(client, "parallel-a@pdn.ac.lk")).json()
    app_ids_a = {a["id"] for a in apps_a}

    apps_b = client.get("/api/applications",
                        headers=_login(client, "parallel-b@pdn.ac.lk")).json()
    app_ids_b = {a["id"] for a in apps_b}

    assert app_ids_a == {app_a.json()["id"]}, "A sees only their app"
    assert app_ids_b == {app_b.json()["id"]}, "B sees only their app"
    assert app_ids_a.isdisjoint(app_ids_b)


def test_acknowledge_notifies_inventor_via_api(client):
    md_hdrs = _md_headers(client)

    inv_email = "ack-flow-a@pdn.ac.lk"
    _register_user(client, md_hdrs, inv_email)

    app = client.post("/api/applications",
                      json=_app_payload("Acknowledge Test", "Ack Flow", inv_email,
                                        "Verifying notify on acknowledge"),
                      headers=md_hdrs)
    assert app.status_code == 200
    app_id = app.json()["id"]

    file_resp = client.post(f"/api/admin/filing/{app_id}/file", headers=md_hdrs)
    assert file_resp.status_code == 200

    ack_resp = client.post(f"/api/admin/filing/{app_id}/acknowledge", headers=md_hdrs)
    assert ack_resp.status_code == 200
    assert ack_resp.json()["status"] == "ACKNOWLEDGED"

    inv_hdrs = _login(client, inv_email)
    notifs = client.get("/api/notifications", headers=inv_hdrs).json()
    ack_notifs = [n for n in notifs if n["subject"] == "Acknowledged"]
    assert len(ack_notifs) == 1, f"{inv_email} should have 1 Acknowledged notification"
    assert ack_notifs[0]["recipient_email"] == inv_email


def test_non_staff_cannot_acknowledge(client):
    md_hdrs = _md_headers(client)

    inv_email = "non-staff-ack@pdn.ac.lk"
    _register_user(client, md_hdrs, inv_email)

    app = client.post("/api/applications",
                      json=_app_payload("Non-Staff Ack Test", "Non-Staff", inv_email),
                      headers=md_hdrs)
    app_id = app.json()["id"]
    client.post(f"/api/admin/filing/{app_id}/file", headers=md_hdrs)

    inv_hdrs = _login(client, inv_email)

    resp = client.post(f"/api/admin/filing/{app_id}/acknowledge", headers=inv_hdrs)
    assert resp.status_code == 403


def test_acknowledge_nonexistent_app_returns_404(client):
    md_hdrs = _md_headers(client)
    resp = client.post("/api/admin/filing/nonexistent-id/acknowledge", headers=md_hdrs)
    assert resp.status_code == 404


def test_acknowledge_without_filing_returns_404(client):
    md_hdrs = _md_headers(client)

    inv_email = "ack-no-file@pdn.ac.lk"
    _register_user(client, md_hdrs, inv_email)

    app = client.post("/api/applications",
                      json=_app_payload("Ack Without Filing", "Ack No File", inv_email),
                      headers=md_hdrs)
    app_id = app.json()["id"]

    resp = client.post(f"/api/admin/filing/{app_id}/acknowledge", headers=md_hdrs)
    assert resp.status_code == 404
