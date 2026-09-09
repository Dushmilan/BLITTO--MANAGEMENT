"""Whole-system e2e with a bunch of users (Playwright vs live server, black-box).

Cast: 1 MD, 2 directors, 6 users — each user's patent in a different state.
Verifies isolation, per-state behavior, shared director/MD worklists,
and staff interplay across the full flow.
"""

from tests.journeys.conftest import (
    journey_create_app,
    journey_download,
    journey_grant,
    journey_login,
    journey_register,
    journey_upload,
    unique_email,
)


def _make_inventor(api, admin_token: str, tag: str):
    email = unique_email(tag)
    journey_register(api, admin_token, email, "user", "pw-123")
    return email, journey_login(api, email, "pw-123")


def _auth(token: str) -> dict[str, str]:
    return {"Authorization": f"Bearer {token}"}


def _setup_bunch(api, admin_token: str):
    """Six inventors, one per lifecycle state. Returns dict tag -> info."""
    bunch = {}
    for tag in ("draft", "filed", "happy", "pend1", "pend2", "rej"):
        email, token = _make_inventor(api, admin_token, tag)
        app_id = journey_create_app(api, admin_token, email, title=f"Patent-{tag}")
        bunch[tag] = {"email": email, "token": token, "app_id": app_id}

    # draft: untouched. filed: moved to FILED.
    r = api.post(
        f"/applications/{bunch['filed']['app_id']}/status?new_status=FILED",
        headers=_auth(admin_token),
    )
    assert r.status == 200, r.text()
    # happy: doc then grant (no warning expected).
    assert journey_upload(
        api, admin_token, bunch["happy"]["app_id"], "grant.pdf", b"%PDF-happy"
    ).status == 200
    g = journey_grant(api, admin_token, bunch["happy"]["app_id"])
    assert g.status == 200 and g.json()["warning"] is None
    # pend1/pend2: granted with no doc (warning expected).
    for tag in ("pend1", "pend2"):
        g = journey_grant(api, admin_token, bunch[tag]["app_id"])
        assert g.status == 200 and g.json()["warning"], tag
    # rej: filed, then rejected.
    for _status in ("FILED", "REJECTED"):
        r = api.post(
            f"/applications/{bunch['rej']['app_id']}/status?new_status={_status}",
            headers=_auth(admin_token),
        )
        assert r.status == 200, r.text()
    return bunch


def test_bunch_isolation_and_per_state_views(api, journey_users) -> None:
    admin_token = journey_users["admin_token"]
    bunch = _setup_bunch(api, admin_token)

    expected_status = {
        "draft": "DRAFT", "filed": "FILED", "happy": "GRANTED",
        "pend1": "GRANTED", "pend2": "GRANTED", "rej": "REJECTED",
    }
    # Every inventor sees exactly their own patent, in the right state.
    for tag, info in bunch.items():
        listed = api.get("/applications", headers=_auth(info["token"])).json()
        assert [a["id"] for a in listed] == [info["app_id"]], tag
        assert listed[0]["status"] == expected_status[tag], tag

    # Admin missing-documents worklist holds exactly the two pending grants.
    # (Session server is shared: assert membership of THIS test's ids only.)
    missing = api.get(
        "/applications/missing-documents", headers=_auth(admin_token)
    ).json()
    missing_ids = {a["id"] for a in missing}
    assert {bunch["pend1"]["app_id"], bunch["pend2"]["app_id"]} <= missing_ids
    for tag in ("draft", "filed", "happy", "rej"):
        assert bunch[tag]["app_id"] not in missing_ids, tag

    # Happy inventor downloads own bytes; nobody else's.
    dl = journey_download(api, bunch["happy"]["token"], bunch["happy"]["app_id"])
    assert dl.status == 200 and dl.body() == b"%PDF-happy"
    assert dl.headers.get("x-downloaded-at")
    cross = journey_download(
        api, bunch["happy"]["token"], bunch["pend1"]["app_id"]
    )
    assert cross.status == 403
    # Pending owners get document_pending; draft/rejected get 403.
    for tag in ("pend1", "pend2"):
        r = journey_download(api, bunch[tag]["token"], bunch[tag]["app_id"])
        assert r.status == 409 and r.json()["detail"]["code"] == "document_pending", tag
    for tag in ("draft", "filed", "rej"):
        r = journey_download(api, bunch[tag]["token"], bunch[tag]["app_id"])
        assert r.status == 403, tag


def test_bunch_request_fulfill_cycle(api, journey_users) -> None:
    admin_token = journey_users["admin_token"]
    bunch = _setup_bunch(api, admin_token)

    # Both pending inventors request; duplicates are rate-limited.
    for tag in ("pend1", "pend2"):
        r = api.post(
            f"/applications/{bunch[tag]['app_id']}/request-document",
            headers=_auth(bunch[tag]["token"]),
        )
        assert r.status == 200, (tag, r.text())
        again = api.post(
            f"/applications/{bunch[tag]['app_id']}/request-document",
            headers=_auth(bunch[tag]["token"]),
        )
        assert again.status == 429, tag
    # One inventor requesting must not block the other (per-user limit).
    # (Implicitly covered: both got 200 above.)

    # Admin fulfills both; worklist drains; downloads carry distinct bytes.
    for tag, payload in (("pend1", b"%PDF-pend1"), ("pend2", b"%PDF-pend2")):
        up = journey_upload(
            api, admin_token, bunch[tag]["app_id"], f"{tag}.pdf", payload
        )
        assert up.status == 200, (tag, up.text())
    missing = api.get(
        "/applications/missing-documents", headers=_auth(admin_token)
    ).json()
    missing_ids = {a["id"] for a in missing}
    assert bunch["pend1"]["app_id"] not in missing_ids
    assert bunch["pend2"]["app_id"] not in missing_ids
    for tag, payload in (("pend1", b"%PDF-pend1"), ("pend2", b"%PDF-pend2")):
        dl = journey_download(api, bunch[tag]["token"], bunch[tag]["app_id"])
        assert dl.status == 200 and dl.body() == payload, tag
    # Fulfilled patents leave the request flow: further requests conflict.
    r = api.post(
        f"/applications/{bunch['pend1']['app_id']}/request-document",
        headers=_auth(bunch["pend1"]["token"]),
    )
    assert r.status == 409


def test_bunch_staff_interplay(api, journey_users) -> None:
    admin_token = journey_users["admin_token"]
    bunch = _setup_bunch(api, admin_token)

    # Register + log in two directors (shared staff powers).
    dir_email, dir2_email = unique_email("staff-dir"), unique_email("staff-dir2")
    journey_register(api, admin_token, dir_email, "director", "pw-123")
    journey_register(api, admin_token, dir2_email, "director", "pw-123")
    dir_token = journey_login(api, dir_email, "pw-123")
    dir2_token = journey_login(api, dir2_email, "pw-123")

    draft_id = bunch["draft"]["app_id"]
    # Director prosecutes the draft patent; user view unaffected.
    oa = api.post(
        f"/applications/{draft_id}/office-actions?kind=OBJECTION&body=clarity",
        headers=_auth(dir_token),
    )
    assert oa.status == 200, oa.text()
    for _status in ("FILED", "EXAMINATION"):
        tr = api.post(
            f"/applications/{draft_id}/status?new_status={_status}",
            headers=_auth(dir2_token),
        )
        assert tr.status == 200, tr.text()
    listed = api.get(
        "/applications", headers=_auth(bunch["draft"]["token"])
    ).json()
    assert listed[0]["status"] == "EXAMINATION"
    # Director may upload; plain user may not.
    assert journey_upload(api, dir_token, draft_id, "draft.pdf", b"%PDF-d").status == 200
    denied = journey_upload(
        api, bunch["draft"]["token"], draft_id, "user.pdf", b"%PDF-u"
    )
    assert denied.status == 403
    # Director/MD share the missing-documents worklist.
    assert api.get("/applications/missing-documents",
                   headers=_auth(dir_token)).status == 200
    # Staff verify-download of the draft doc (staff bypass, pinned).
    assert journey_download(api, dir_token, draft_id).status == 200
