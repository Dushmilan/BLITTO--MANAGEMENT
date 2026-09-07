"""Edge cases: lifecycle, documents, download, request-doc, notifications."""

import pytest
from pydantic import ValidationError

from app.domain.common import ApplicationStatus
from app.modules.application_intake.models import Disclosure
from app.modules.notification.local import LocalNotificationModule
from tests.conftest import auth_headers

PDF = b"%PDF-1.4 edge"
CT = {"Content-Type": "application/pdf"}


def _upload(c, token: str, app_id: str, filename: str, content: bytes = PDF):
    return c.post(
        f"/applications/{app_id}/documents",
        params={"filename": filename},
        content=content,
        headers={**auth_headers(token), **CT},
    )


def _grant(c, token: str, app_id: str):
    return c.post(
        f"/applications/{app_id}/status",
        params={"new_status": "GRANTED"},
        headers=auth_headers(token),
    )


# --- Lifecycle edges ---

def test_status_change_unknown_application_is_404(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    r = c.post(
        "/applications/does-not-exist/status",
        params={"new_status": "GRANTED"},
        headers=auth_headers(tokens["md"]),
    )
    assert r.status_code == 404


def test_status_change_invalid_status_is_422(seeded) -> None:
    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    r = c.post(
        f"/applications/{app_id}/status",
        params={"new_status": "SOMEDAY"},
        headers=auth_headers(tokens["md"]),
    )
    assert r.status_code == 422


def test_create_application_rejects_foreign_inventor_mail(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    r = c.post(
        "/applications",
        json={"inventor_name": "X", "inventor_email": "x@gmail.com",
              "title": "T", "summary": "s"},
        headers=auth_headers(tokens["md"]),
    )
    assert r.status_code == 422


def test_create_application_rejects_multi_author(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    r = c.post(
        "/applications",
        json={"inventor_name": "A, B", "inventor_email": "a@pdn.ac.lk",
              "title": "T", "summary": "s"},
        headers=auth_headers(tokens["md"]),
    )
    assert r.status_code == 422


def test_double_grant_with_docs_has_no_warning(seeded) -> None:
    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    assert _upload(c, tokens["md"], app_id, "g.pdf").status_code == 200
    assert _grant(c, tokens["md"], app_id).json()["warning"] is None
    again = _grant(c, tokens["md"], app_id)
    assert again.status_code == 200
    assert again.json()["warning"] is None


def test_grant_without_docs_warns_every_time(seeded) -> None:
    from app.main import app as fastapi_app

    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    assert _grant(c, tokens["md"], app_id).json()["warning"]
    assert _grant(c, tokens["md"], app_id).json()["warning"]
    audits = fastapi_app.state.audit.for_application(app_id)
    assert sum(1 for a in audits if a.action == "grant_without_document") == 2


# --- Document edges ---

def test_upload_to_unknown_application_is_404(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    r = _upload(c, tokens["md"], "no-such-app", "g.pdf")
    assert r.status_code == 404


def test_empty_file_upload_and_download(seeded) -> None:
    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    assert _upload(c, tokens["md"], app_id, "empty.pdf", b"").status_code == 200
    _grant(c, tokens["md"], app_id)
    dl = c.get(f"/applications/{app_id}/download",
               headers=auth_headers(tokens["user_a"]))
    assert dl.status_code == 200
    assert dl.content == b""


def test_lost_backend_content_is_404(seeded) -> None:
    from app.main import app as fastapi_app

    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    assert _upload(c, tokens["md"], app_id, "g.pdf").status_code == 200
    _grant(c, tokens["md"], app_id)
    vault = fastapi_app.state.document_vault
    for doc in vault.list_for_application(app_id):
        vault._store._data.pop(doc.content_ref, None)
    r = c.get(f"/applications/{app_id}/download",
              headers=auth_headers(tokens["user_a"]))
    assert r.status_code == 404


def test_extensionless_and_uppercase_filenames(seeded) -> None:
    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    assert _upload(c, tokens["md"], app_id, "GRANTFILE", PDF).status_code == 200
    _grant(c, tokens["md"], app_id)
    dl = c.get(f"/applications/{app_id}/download",
               headers=auth_headers(tokens["user_a"]))
    assert dl.status_code == 200
    assert dl.headers["content-type"] == "application/octet-stream"
    assert "granted_" in dl.headers["content-disposition"]


def test_uppercase_pdf_extension_detected(seeded) -> None:
    from app.main import app as fastapi_app

    c, tokens = seeded["client"], seeded["tokens"]
    app_id = fastapi_app.state.application_intake.create_application_shell(
        Disclosure(inventor_name="U", inventor_email="user-a@pdn.ac.lk",
                   title="U2", summary="s")
    ).id
    assert _upload(c, tokens["md"], app_id, "GRANT.PDF", PDF).status_code == 200
    _grant(c, tokens["md"], app_id)
    dl = c.get(f"/applications/{app_id}/download",
               headers=auth_headers(tokens["user_a"]))
    assert dl.headers["content-type"] == "application/pdf"


def test_staff_may_download_ungranted_for_verification(seeded) -> None:
    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    assert _upload(c, tokens["md"], app_id, "draft.pdf").status_code == 200
    dl = c.get(f"/applications/{app_id}/download",
               headers=auth_headers(tokens["md"]))
    assert dl.status_code == 200  # staff bypasses the GRANTED gate


def test_download_unknown_application_is_404(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    r = c.get("/applications/nope/download", headers=auth_headers(tokens["md"]))
    assert r.status_code == 404


def test_missing_documents_excludes_healthy_apps(seeded) -> None:
    from app.main import app as fastapi_app

    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    # DRAFT app with no docs: not listed. GRANTED with docs: not listed.
    _upload(c, tokens["md"], app_id, "g.pdf")
    _grant(c, tokens["md"], app_id)
    other = fastapi_app.state.application_intake.create_application_shell(
        Disclosure(inventor_name="O", inventor_email="user-b@pdn.ac.lk",
                   title="O", summary="s")
    ).id
    missing = c.get("/applications/missing-documents",
                    headers=auth_headers(tokens["md"])).json()
    assert {a["id"] for a in missing} == set()
    # Now grant the doc-less app -> it appears.
    c.post(f"/applications/{other}/status", params={"new_status": "GRANTED"},
           headers=auth_headers(tokens["md"]))
    missing = c.get("/applications/missing-documents",
                    headers=auth_headers(tokens["md"])).json()
    assert {a["id"] for a in missing} == {other}


# --- Request-document edges ---

def test_request_doc_unknown_app_is_404(seeded) -> None:
    c, tokens = seeded["client"], seeded["tokens"]
    r = c.post("/applications/nope/request-document",
               headers=auth_headers(tokens["user_a"]))
    assert r.status_code == 404


def test_request_doc_non_granted_is_409(seeded) -> None:
    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    r = c.post(f"/applications/{app_id}/request-document",
               headers=auth_headers(tokens["user_a"]))
    assert r.status_code == 409


def test_request_doc_when_doc_exists_is_409(seeded) -> None:
    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    _upload(c, tokens["md"], app_id, "g.pdf")
    _grant(c, tokens["md"], app_id)
    r = c.post(f"/applications/{app_id}/request-document",
               headers=auth_headers(tokens["user_a"]))
    assert r.status_code == 409


def test_request_doc_other_inventor_is_403(seeded) -> None:
    from app.main import app as fastapi_app

    c, tokens = seeded["client"], seeded["tokens"]
    other = fastapi_app.state.application_intake.create_application_shell(
        Disclosure(inventor_name="B", inventor_email="user-b@pdn.ac.lk",
                   title="B", summary="s")
    ).id
    c.post(f"/applications/{other}/status", params={"new_status": "GRANTED"},
           headers=auth_headers(tokens["md"]))
    r = c.post(f"/applications/{other}/request-document",
               headers=auth_headers(tokens["user_a"]))
    assert r.status_code == 403


def test_request_doc_anonymous_is_401(seeded) -> None:
    c, app_id = seeded["client"], seeded["app_id"]
    assert c.post(f"/applications/{app_id}/request-document").status_code == 401


# --- Notification side-effect edges ---

def test_grant_without_doc_emits_both_notifications(seeded) -> None:
    from app.main import app as fastapi_app

    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    _grant(c, tokens["md"], app_id)
    kinds = [n.kind for n in fastapi_app.state.notification.sent_history()]
    assert "grant_without_document" in kinds
    pending = [n for n in fastapi_app.state.notification.sent_history()
               if n.kind == "status_change" and "pending" in n.body.lower()]
    assert pending, "inventor should get the pending-document variant"


def test_late_upload_emits_download_ready(seeded) -> None:
    from app.main import app as fastapi_app

    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    _grant(c, tokens["md"], app_id)
    _upload(c, tokens["md"], app_id, "late.pdf")
    kinds = [n.kind for n in fastapi_app.state.notification.sent_history()]
    assert "download_ready" in kinds


def test_second_upload_does_not_resend_ready(seeded) -> None:
    from app.main import app as fastapi_app

    c, tokens, app_id = seeded["client"], seeded["tokens"], seeded["app_id"]
    _grant(c, tokens["md"], app_id)
    _upload(c, tokens["md"], app_id, "one.pdf")
    _upload(c, tokens["md"], app_id, "two.pdf")
    kinds = [n.kind for n in fastapi_app.state.notification.sent_history()]
    assert kinds.count("download_ready") == 1


def test_module_falls_back_on_unknown_status() -> None:
    note = LocalNotificationModule()
    sent = note.send_status_change("a@pdn.ac.lk", "app-9", "SOMEDAY")
    assert sent.kind == "status_change"
    assert "SOMEDAY" in sent.body


def test_disclosure_blank_name_rejected() -> None:
    with pytest.raises(ValidationError):
        Disclosure(inventor_name="   ", inventor_email="a@pdn.ac.lk",
                   title="T", summary="s")


def test_application_status_enum_roundtrip() -> None:
    assert ApplicationStatus("GRANTED") is ApplicationStatus.GRANTED
    with pytest.raises(ValueError):
        ApplicationStatus("SOMEDAY")
