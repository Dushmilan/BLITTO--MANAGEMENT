"""End-to-end flow integration tests — multi-step user journeys through the API."""


def _admin_headers(client):
    resp = client.post("/api/auth/login", json={
        "email": "admin@blitto.local",
        "password": "admin1234",
    })
    return {"Authorization": f"Bearer {resp.json()['access_token']}"}


def test_happy_path_full_grant(client):
    """Admin registers inventor → creates application → files → office action → grant."""
    admin_hdrs = _admin_headers(client)

    # 1. Admin registers inventor
    reg = client.post("/api/auth/register", json={
        "email": "happy-flow@uni.edu",
        "password": "secret",
        "name": "Flow Inventor",
        "role": "inventor",
    }, headers=admin_hdrs)
    assert reg.status_code in (200, 201), reg.text

    # 2. Admin creates application (inventor cannot: StaffUser gate)
    app = client.post("/api/applications", json={
        "title": "Flow Test Invention",
        "inventors": [{"inventor_name": "Flow Inventor", "inventor_email": "happy-flow@uni.edu"}],
        "summary": "End-to-end flow test",
    }, headers=admin_hdrs)
    assert app.status_code == 200, app.text
    app_id = app.json()["id"]

    # 3. Admin files the application
    file_resp = client.post(f"/api/admin/filing/{app_id}/file", headers=admin_hdrs)
    assert file_resp.status_code == 200, file_resp.text

    # 4. Admin sends office action
    oa = client.post(f"/api/applications/{app_id}/office-actions",
                      params={"kind": "REJECTION", "body": "Need more details"},
                      headers=admin_hdrs)
    assert oa.status_code == 200, oa.text

    # 5. Admin grants patent
    grant = client.post(f"/api/admin/filing/{app_id}/grant",
                         params={"patent_number": "LK/PAT/2026/999"},
                         headers=admin_hdrs)
    assert grant.status_code == 200, grant.text

    # 6. Verify final status
    status = client.get(f"/api/admin/filing/{app_id}/status", headers=admin_hdrs)
    assert status.status_code == 200
    assert status.json()["granted_at"] is not None
    assert status.json()["patent_number"] == "LK/PAT/2026/999"


def test_rejection_path(client):
    """Create → file → reject (no response)."""
    admin_hdrs = _admin_headers(client)

    app = client.post("/api/applications", json={
        "title": "Rejection Flow Test",
        "inventors": [{"inventor_name": "Rejected", "inventor_email": "rejected-flow@uni.edu"}],
        "summary": "Will be rejected",
    }, headers=admin_hdrs)
    app_id = app.json()["id"]

    client.post(f"/api/admin/filing/{app_id}/file", headers=admin_hdrs)

    reject = client.post(f"/api/admin/filing/{app_id}/reject", headers=admin_hdrs)
    assert reject.status_code == 200, reject.text

    status = client.get(f"/api/admin/filing/{app_id}/status", headers=admin_hdrs)
    assert status.json()["rejected_at"] is not None


def test_defect_sheet_flow(client):
    """Create → file → defect → acknowledge → grant."""
    admin_hdrs = _admin_headers(client)

    app = client.post("/api/applications", json={
        "title": "Defect Flow Test",
        "inventors": [{"inventor_name": "Defect Tester", "inventor_email": "defect-flow@uni.edu"}],
        "summary": "",
    }, headers=admin_hdrs)
    app_id = app.json()["id"]

    client.post(f"/api/admin/filing/{app_id}/file", headers=admin_hdrs)

    defect = client.post(
        f"/api/admin/filing/{app_id}/defect-sheets",
        params={"sheet_number": 1, "description": "Missing signature"},
        headers=admin_hdrs,
    )
    assert defect.status_code == 200

    apps = client.get("/api/applications", headers=admin_hdrs).json()
    assert any(a["status"] == "DEFECT_SHEET_1" for a in apps if a["id"] == app_id)

    sheets = client.get(f"/api/admin/filing/{app_id}/defect-sheets", headers=admin_hdrs)
    assert sheets.status_code == 200
    assert len(sheets.json()) >= 1

    ack = client.post(f"/api/admin/filing/{app_id}/acknowledge", headers=admin_hdrs)
    assert ack.status_code == 200

    grant = client.post(f"/api/admin/filing/{app_id}/grant",
                         params={"patent_number": "LK/PAT/2026/888"},
                         headers=admin_hdrs)
    assert grant.status_code == 200


def test_notification_during_flow(client):
    """Notifications are sent at key milestones during the filing flow."""
    admin_hdrs = _admin_headers(client)

    login_inv = client.post("/api/auth/login", json={
        "email": "inventor1@peradeniya.lk",
        "password": "password",
    })
    inv_hdrs = {"Authorization": f"Bearer {login_inv.json()['access_token']}"}

    before = len(client.get("/api/notifications", headers=inv_hdrs).json())

    app = client.post("/api/applications", json={
        "title": "Notification Flow Test",
        "inventors": [{"inventor_name": "Inv1", "inventor_email": "inventor1@peradeniya.lk"}],
        "summary": "",
    }, headers=admin_hdrs)
    app_id = app.json()["id"]

    client.post(f"/api/admin/filing/{app_id}/file", headers=admin_hdrs)

    after = client.get("/api/notifications", headers=inv_hdrs)
    assert len(after.json()) > before, "Expected new notification after filing"


def test_document_vault_integration(client):
    """Upload → lock → upload blocked → unlock → upload works."""
    admin_hdrs = _admin_headers(client)

    app = client.post("/api/applications", json={
        "title": "Vault Integration Test",
        "inventors": [{"inventor_name": "Vault Tester", "inventor_email": "vault-flow@uni.edu"}],
        "summary": "",
    }, headers=admin_hdrs)
    app_id = app.json()["id"]

    up1 = client.post(f"/api/applications/{app_id}/documents?filename=doc1.pdf",
                       headers=admin_hdrs, content=b"content")
    assert up1.status_code == 200

    client.post("/api/vault/lock", headers=admin_hdrs)

    up2 = client.post(f"/api/applications/{app_id}/documents?filename=doc2.pdf",
                       headers=admin_hdrs, content=b"more")
    assert up2.status_code == 423

    client.post("/api/vault/unlock", json={"master_key": "dummy-key"}, headers=admin_hdrs)

    up3 = client.post(f"/api/applications/{app_id}/documents?filename=doc3.pdf",
                       headers=admin_hdrs, content=b"more")
    assert up3.status_code == 200


def test_staff_can_manage_applications(client):
    """Staff can create, file, upload, and manage applications."""
    admin_hdrs = _admin_headers(client)

    app = client.post("/api/applications", json={
        "title": "Staff-Managed App",
        "inventors": [{"inventor_name": "Alice", "inventor_email": "alice-flow@uni.edu"}],
        "summary": "Managed by staff",
    }, headers=admin_hdrs)
    assert app.status_code == 200
    app_id = app.json()["id"]

    file_resp = client.post(f"/api/admin/filing/{app_id}/file", headers=admin_hdrs)
    assert file_resp.status_code == 200

    doc = client.post(f"/api/applications/{app_id}/documents?filename=staff.pdf",
                       headers=admin_hdrs, content=b"staff content")
    assert doc.status_code == 200

    notify = client.post(
        f"/api/applications/{app_id}/notify",
        params={"subject": "Update", "body": "Your application is progressing"},
        headers=admin_hdrs,
    )
    assert notify.status_code == 200


def test_inventor_sees_only_own_applications(client):
    """Inventors only see their own applications when listing."""
    login1 = client.post("/api/auth/login", json={
        "email": "inventor1@peradeniya.lk",
        "password": "password",
    })
    inv1_hdrs = {"Authorization": f"Bearer {login1.json()['access_token']}"}
    apps1 = {a["id"] for a in client.get("/api/applications", headers=inv1_hdrs).json()}

    login2 = client.post("/api/auth/login", json={
        "email": "inventor2@peradeniya.lk",
        "password": "password",
    })
    inv2_hdrs = {"Authorization": f"Bearer {login2.json()['access_token']}"}
    apps2 = {a["id"] for a in client.get("/api/applications", headers=inv2_hdrs).json()}

    assert apps1.isdisjoint(apps2), "Inventors should not see each other's applications"


def test_inventor_can_see_own_notifications(client):
    """Inventor sees only notifications addressed to them."""
    admin_hdrs = _admin_headers(client)

    login_inv = client.post("/api/auth/login", json={
        "email": "inventor1@peradeniya.lk",
        "password": "password",
    })
    inv_hdrs = {"Authorization": f"Bearer {login_inv.json()['access_token']}"}

    notifs = client.get("/api/notifications", headers=inv_hdrs)
    assert notifs.status_code == 200
    all_recipients = [n.get("recipient_email", "") for n in notifs.json()]
    if all_recipients:
        assert all(r == "inventor1@peradeniya.lk" for r in all_recipients)


def test_parallel_flow_admin_and_inventor_views(client):
    """Admin creates apps for two inventors; each sees only their own."""
    admin_hdrs = _admin_headers(client)
    reg1 = client.post("/api/auth/register", json={
        "email": "parallel-a@uni.edu", "password": "secret",
        "name": "Parallel A", "role": "inventor",
    }, headers=admin_hdrs)
    reg2 = client.post("/api/auth/register", json={
        "email": "parallel-b@uni.edu", "password": "secret",
        "name": "Parallel B", "role": "inventor",
    }, headers=admin_hdrs)

    app_a = client.post("/api/applications", json={
        "title": "Parallel App A",
        "inventors": [{"inventor_name": "Parallel A", "inventor_email": "parallel-a@uni.edu"}],
        "summary": "",
    }, headers=admin_hdrs)
    app_b = client.post("/api/applications", json={
        "title": "Parallel App B",
        "inventors": [{"inventor_name": "Parallel B", "inventor_email": "parallel-b@uni.edu"}],
        "summary": "",
    }, headers=admin_hdrs)

    login_a = client.post("/api/auth/login", json={
        "email": "parallel-a@uni.edu", "password": "secret",
    })
    apps_a = client.get("/api/applications", headers={
        "Authorization": f"Bearer {login_a.json()['access_token']}"
    }).json()
    app_ids_a = {a["id"] for a in apps_a}

    login_b = client.post("/api/auth/login", json={
        "email": "parallel-b@uni.edu", "password": "secret",
    })
    apps_b = client.get("/api/applications", headers={
        "Authorization": f"Bearer {login_b.json()['access_token']}"
    }).json()
    app_ids_b = {a["id"] for a in apps_b}

    assert app_ids_a == {app_a.json()["id"]}, "A sees only their app"
    assert app_ids_b == {app_b.json()["id"]}, "B sees only their app"
    assert app_ids_a.isdisjoint(app_ids_b)


def test_acknowledge_notifies_both_inventors_via_api(client):
    admin_hdrs = _admin_headers(client)

    inv1_email = "ack-flow-a@uni.edu"
    inv2_email = "ack-flow-b@uni.edu"

    for email in (inv1_email, inv2_email):
        client.post("/api/auth/register", json={
            "email": email, "password": "secret",
            "name": f"Ack Flow {email[0]}", "role": "inventor",
        }, headers=admin_hdrs)

    app = client.post("/api/applications", json={
        "title": "Two-Inventor Acknowledge Test",
        "inventors": [
            {"inventor_name": "Ack Flow A", "inventor_email": inv1_email},
            {"inventor_name": "Ack Flow B", "inventor_email": inv2_email},
        ],
        "summary": "Verifying both get notified on acknowledge",
    }, headers=admin_hdrs)
    assert app.status_code == 200
    app_id = app.json()["id"]

    file_resp = client.post(f"/api/admin/filing/{app_id}/file", headers=admin_hdrs)
    assert file_resp.status_code == 200

    ack_resp = client.post(f"/api/admin/filing/{app_id}/acknowledge", headers=admin_hdrs)
    assert ack_resp.status_code == 200
    assert ack_resp.json()["status"] == "ACKNOWLEDGED"

    for email in (inv1_email, inv2_email):
        login = client.post("/api/auth/login", json={
            "email": email, "password": "secret",
        })
        inv_hdrs = {"Authorization": f"Bearer {login.json()['access_token']}"}
        notifs = client.get("/api/notifications", headers=inv_hdrs).json()
        ack_notifs = [n for n in notifs if n["subject"] == "Acknowledged"]
        assert len(ack_notifs) == 1, f"{email} should have 1 Acknowledged notification"
        assert ack_notifs[0]["recipient_email"] == email


def test_non_admin_cannot_acknowledge(client):
    admin_hdrs = _admin_headers(client)

    inv_email = "non-admin-ack@uni.edu"
    client.post("/api/auth/register", json={
        "email": inv_email, "password": "secret",
        "name": "Non-Admin Ack", "role": "inventor",
    }, headers=admin_hdrs)

    app = client.post("/api/applications", json={
        "title": "Non-Admin Ack Test",
        "inventors": [{"inventor_name": "Non-Admin", "inventor_email": inv_email}],
        "summary": "",
    }, headers=admin_hdrs)
    app_id = app.json()["id"]
    client.post(f"/api/admin/filing/{app_id}/file", headers=admin_hdrs)

    login = client.post("/api/auth/login", json={
        "email": inv_email, "password": "secret",
    })
    inv_hdrs = {"Authorization": f"Bearer {login.json()['access_token']}"}

    resp = client.post(f"/api/admin/filing/{app_id}/acknowledge", headers=inv_hdrs)
    assert resp.status_code == 403


def test_acknowledge_nonexistent_app_returns_404(client):
    admin_hdrs = _admin_headers(client)
    resp = client.post("/api/admin/filing/nonexistent-id/acknowledge", headers=admin_hdrs)
    assert resp.status_code == 404


def test_acknowledge_without_filing_returns_404(client):
    admin_hdrs = _admin_headers(client)

    inv_email = "ack-no-file@uni.edu"
    client.post("/api/auth/register", json={
        "email": inv_email, "password": "secret",
        "name": "Ack No File", "role": "inventor",
    }, headers=admin_hdrs)

    app = client.post("/api/applications", json={
        "title": "Ack Without Filing",
        "inventors": [{"inventor_name": "Ack No File", "inventor_email": inv_email}],
        "summary": "",
    }, headers=admin_hdrs)
    app_id = app.json()["id"]

    resp = client.post(f"/api/admin/filing/{app_id}/acknowledge", headers=admin_hdrs)
    assert resp.status_code == 404
