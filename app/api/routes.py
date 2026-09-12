"""HTTP routes for all modules (thin adapters over module interfaces)."""

from __future__ import annotations

import io
import mimetypes
import time
import zipfile
from datetime import datetime, timezone
from typing import Optional

from fastapi import APIRouter, Depends, HTTPException, Query, Request, Response
from fastapi.responses import StreamingResponse

from app.api.deps import AdminUser, CurrentUser, MDUser, StaffUser, UnlockedVault, User
from app.core.config import settings
from app.core.email_policy import is_institution_email

from app.domain.common import ApplicationStatus, Role
from app.modules.application_intake.models import Disclosure
from app.modules.authorization.models import LoginRequest, RegisterRequest
from app.modules.docketing.models import DeadlineType
from app.modules.prosecution.models import OfficeActionKind
from app.modules.notification.models import Notification

router = APIRouter()


# --- Authorization ---
# Only the MD manages users / issues tokens for others.
@router.post("/auth/register", tags=["authorization"])
async def auth_register(
    request: Request, body: RegisterRequest, user: User = Depends(CurrentUser)
):
    # Privilege check: only the MD may create director/MD roles.
    # Users self-register with role=user.
    if body.role != Role.USER and user.role != Role.MD:
        raise HTTPException(status_code=403, detail="Only MD may create director/MD roles")
    try:
        return request.app.state.authorization.register(body)
    except ValueError as exc:
        # Institution-mail rejections are client errors (422); duplicates are 409.
        if "Institution email required" in str(exc):
            raise HTTPException(status_code=422, detail=str(exc))
        raise HTTPException(status_code=409, detail=str(exc))


@router.post("/auth/token", tags=["authorization"])
async def auth_token(request: Request, md: User = Depends(MDUser)):
    # Issue a token for the authenticated MD only (no minting for arbitrary emails).
    token = request.app.state.authorization.issue_token(md.email)
    if token is None:
        raise HTTPException(status_code=404, detail="Unknown email")
    return token


# Public login (user self-login flow). Institution mail only.
@router.post("/auth/login", tags=["authorization"])
async def auth_login(request: Request, body: LoginRequest):
    if not is_institution_email(body.email):
        raise HTTPException(status_code=403, detail="Institution email required")
    token = request.app.state.authorization.login(body)
    if token is None:
        raise HTTPException(status_code=401, detail="Invalid credentials")
    return token


@router.get("/auth/me", tags=["authorization"])
async def auth_me(user: User = Depends(CurrentUser)):
    return user


# --- Application Intake ---
@router.post("/applications", tags=["applicationIntake"])
async def create_application(
    request: Request, body: Disclosure, user: User = Depends(StaffUser)
):
    application = request.app.state.application_intake.create_application_shell(body)
    request.app.state.audit.record(
        "create_application", user_id=getattr(user, "id", None),
        application_id=application.id,
    )
    return application


@router.get("/applications", tags=["applicationIntake"])
async def list_applications(request: Request, user: User = Depends(CurrentUser)):
    # Confidentiality invariant #6: users see only their own.
    applications = request.app.state.application_intake.list_applications()
    if user.role == Role.USER:
        applications = [a for a in applications if a.inventor_email == user.email]
    return applications


# --- Docketing ---
@router.post("/applications/{application_id}/deadlines", tags=["docketing"])
async def add_deadline(
    request: Request,
    application_id: str,
    deadline_type: DeadlineType = Query(..., alias="type"),
    due_date: datetime = Query(...),
    user: User = Depends(StaffUser),
):
    deadline = request.app.state.docketing.add_deadline(
        application_id, deadline_type, due_date
    )
    request.app.state.audit.record(
        f"add_deadline:{deadline_type.value}",
        user_id=getattr(user, "id", None),
        application_id=application_id,
    )
    return deadline


@router.get("/deadlines", tags=["docketing"])
async def list_deadlines(
    request: Request,
    application_id: Optional[str] = Query(None),
    user: User = Depends(StaffUser),
):
    return request.app.state.docketing.list_deadlines(application_id)


# --- Vault unlock/lock/status ---

_UNLOCK_ATTEMPTS: dict[str, list[float]] = {}
_WINDOW_S = 600.0
_MAX_FAILS = 5


def _unlock_allowed(ip: str) -> bool:
    now = time.monotonic()
    hits = [t for t in _UNLOCK_ATTEMPTS.get(ip, []) if now - t < _WINDOW_S]
    _UNLOCK_ATTEMPTS[ip] = hits
    return len(hits) < _MAX_FAILS


def _record_unlock_fail(ip: str) -> None:
    _UNLOCK_ATTEMPTS.setdefault(ip, []).append(time.monotonic())


@router.get("/vault/status", tags=["documentVault"])
async def vault_status(request: Request, user: User = Depends(StaffUser)):
    vault = request.app.state.document_vault
    return {
        "locked": not vault.is_unlocked(),
        "remaining_seconds": vault.unlock_remaining(),
    }


@router.post("/vault/unlock", tags=["documentVault"])
async def vault_unlock(
    request: Request,
    body: dict,
    user: User = Depends(StaffUser),
):
    ip = request.client.host if request.client else "unknown"
    if not _unlock_allowed(ip):
        raise HTTPException(status_code=429, detail="Too many unlock attempts")
    ok = request.app.state.document_vault.unlock(body.get("master_key", ""))
    if not ok:
        _record_unlock_fail(ip)
        raise HTTPException(status_code=401, detail="Invalid master key")
    _UNLOCK_ATTEMPTS.pop(ip, None)
    return {"status": "unlocked"}


@router.post("/vault/lock", tags=["documentVault"])
async def vault_lock(request: Request, user: User = Depends(StaffUser)):
    request.app.state.document_vault.lock()
    return {"status": "locked"}


# --- Document Vault ---
# Users have no upload capability (Readme): gate to director/MD.

_ALLOWED_EXTENSIONS = frozenset({
    ".pdf", ".doc", ".docx", ".xls", ".xlsx",
    ".txt", ".csv", ".jpg", ".jpeg", ".png",
})


def _validate_extension(filename: str) -> str:
    ext = (mimetypes.guess_type(filename)[0] or "").lower()
    _, raw_ext = (filename.rsplit(".", 1) if "." in filename else ("", ""))
    dot_ext = f".{raw_ext.lower()}" if raw_ext else ""
    if dot_ext not in _ALLOWED_EXTENSIONS:
        raise HTTPException(
            status_code=422,
            detail=f"File type '{dot_ext}' not allowed. Accepted: {', '.join(sorted(_ALLOWED_EXTENSIONS))}",
        )
    return dot_ext


@router.post("/applications/{application_id}/documents", tags=["documentVault"])
async def store_document(
    request: Request,
    application_id: str,
    filename: str = Query(...),
    user: User = Depends(CurrentUser),
    _: None = Depends(UnlockedVault),
):
    if user.role not in (Role.DIRECTOR, Role.MD):
        raise HTTPException(status_code=403, detail="Upload not permitted for this role")
    if request.app.state.application_intake.get_application(application_id) is None:
        raise HTTPException(status_code=404, detail="Unknown application")
    _validate_extension(filename)
    # Cap upload size to avoid memory exhaustion (local skeleton: 10 MB).
    body = await request.body()
    if len(body) > 10 * 1024 * 1024:
        raise HTTPException(status_code=413, detail="Document too large (max 10 MB)")
    document = request.app.state.document_vault.store(
        application_id, filename, body, getattr(user, "email", "unknown")
    )
    request.app.state.audit.record(
        "store_document", user_id=getattr(user, "id", None),
        application_id=application_id,
    )
    # Late upload for an already-granted patent: tell the inventor it is ready.
    application = request.app.state.application_intake.get_application(application_id)
    if (
        application is not None
        and application.status == ApplicationStatus.GRANTED
        and len(request.app.state.document_vault.list_for_application(application_id)) == 1
    ):
        request.app.state.notification.send_download_ready(
            application.inventor_email, application.id
        )
    return document


@router.get("/applications/{application_id}/documents", tags=["documentVault"])
async def list_documents(
    request: Request, application_id: str, user: User = Depends(StaffUser)
):
    return request.app.state.document_vault.list_for_application(application_id)


def _safe_filename(name: str) -> str:
    # Strip path components and header-breaking characters.
    return "".join(
        c for c in name.split("/")[-1].split("\\")[-1] if c not in '"\r\n'
    ).strip() or "patent"


# Director/MD worklist: GRANTED applications still missing their patent document.
@router.get("/applications/missing-documents", tags=["documentVault"])
async def grants_missing_documents(
    request: Request, user: User = Depends(StaffUser)
):
    result = []
    for application in request.app.state.application_intake.list_applications():
        if application.status != ApplicationStatus.GRANTED:
            continue
        docs = request.app.state.document_vault.list_for_application(application.id)
        if not docs:
            result.append(application)
    return result


# User download: own GRANTED patent as a file, timestamped + audited.
@router.get("/applications/{application_id}/download", tags=["documentVault"])
async def download_granted_patent(
    request: Request, application_id: str, user: User = Depends(CurrentUser)
):
    application = request.app.state.application_intake.get_application(application_id)
    if application is None:
        raise HTTPException(status_code=404, detail="Unknown application")
    if user.role == Role.USER:
        if application.inventor_email != user.email:
            raise HTTPException(status_code=403, detail="Not your patent")
        if application.status != ApplicationStatus.GRANTED:
            raise HTTPException(status_code=403, detail="Download available only for GRANTED patents")
    docs = request.app.state.document_vault.list_for_application(application_id)
    if not docs:
        raise HTTPException(
            status_code=409,
            detail={
                "code": "document_pending",
                "message": (
                    "Patent is GRANTED but the document has not been uploaded yet. "
                    "You can request it via POST "
                    f"/applications/{application_id}/request-document."
                ),
            },
        )
    now = datetime.now(timezone.utc)
    stamp = now.strftime("%Y%m%dT%H%M%SZ")
    iso_ts = now.isoformat()
    vault = request.app.state.document_vault
    if len(docs) == 1:
        content = vault.retrieve(docs[0].id)
        if content is None:
            raise HTTPException(status_code=404, detail="Document content missing")
        filename = _safe_filename(docs[0].filename)
        stem, _, ext = filename.rpartition(".")
        out_name = f"{stem or filename}_granted_{stamp}.{ext}" if ext else f"{filename}_granted_{stamp}"
        media = "application/pdf" if ext.lower() == "pdf" else "application/octet-stream"
        body = content
    else:
        buf = io.BytesIO()
        with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
            for doc in docs:
                content = vault.retrieve(doc.id)
                if content is not None:
                    zf.writestr(_safe_filename(doc.filename), content)
        body = buf.getvalue()
        out_name = f"{application_id}_granted_{stamp}.zip"
        media = "application/zip"
    request.app.state.audit.record(
        "download_granted_patent",
        user_id=getattr(user, "id", None),
        application_id=application_id,
    )
    return Response(
        content=body,
        media_type=media,
        headers={
            "Content-Disposition": f'attachment; filename="{out_name}"',
            "X-Downloaded-At": iso_ts,
        },
    )


# User asks director/MD to upload the missing granted document (rate-limited).
@router.post("/applications/{application_id}/request-document", tags=["documentVault"])
async def request_granted_document(
    request: Request, application_id: str, user: User = Depends(CurrentUser)
):
    application = request.app.state.application_intake.get_application(application_id)
    if application is None:
        raise HTTPException(status_code=404, detail="Unknown application")
    if user.role == Role.USER and application.inventor_email != user.email:
        raise HTTPException(status_code=403, detail="Not your patent")
    if application.status != ApplicationStatus.GRANTED:
        raise HTTPException(status_code=409, detail="Patent is not GRANTED")
    if request.app.state.document_vault.list_for_application(application_id):
        raise HTTPException(status_code=409, detail="Document already available")
    prior = [
        e for e in request.app.state.audit.for_application(application_id)
        if e.action == "document_requested" and e.user_id == getattr(user, "id", None)
    ]
    if prior:
        raise HTTPException(status_code=429, detail="Document already requested")
    request.app.state.audit.record(
        "document_requested",
        user_id=getattr(user, "id", None),
        application_id=application_id,
    )
    admin_inbox = settings.bootstrap_admin_email or "md@pdn.ac.lk"
    request.app.state.notification.send_document_request(
        admin_inbox, application.id, application.inventor_email
    )
    return {"status": "requested", "application_id": application_id}


@router.get("/applications/{application_id}/documents/{document_id}/download", tags=["documentVault"])
async def download_document(
    request: Request,
    application_id: str,
    document_id: str,
    user: User = Depends(StaffUser),
    _: None = Depends(UnlockedVault),
):
    # Scope check first: the document must belong to the URL application,
    # otherwise a staff caller could pull another application's file by id.
    docs = request.app.state.document_vault.list_for_application(application_id)
    doc_meta = next((d for d in docs if d.id == document_id), None)
    if doc_meta is None:
        raise HTTPException(status_code=404, detail="Document not found")
    content = request.app.state.document_vault.retrieve(document_id)
    if content is None:
        raise HTTPException(status_code=404, detail="Document not found")
    filename = _safe_filename(doc_meta.filename)
    media_type = mimetypes.guess_type(filename)[0] or "application/octet-stream"
    return StreamingResponse(
        iter([content]),
        media_type=media_type,
        headers={"Content-Disposition": f'attachment; filename="{filename}"'},
    )


@router.delete("/applications/{application_id}/documents/{document_id}", tags=["documentVault"])
async def delete_document(
    request: Request,
    application_id: str,
    document_id: str,
    user: User = Depends(CurrentUser),
    _: None = Depends(UnlockedVault),
):
    if user.role not in (Role.DIRECTOR, Role.MD):
        raise HTTPException(status_code=403, detail="Delete not permitted for this role")
    docs = request.app.state.document_vault.list_for_application(application_id)
    if not any(d.id == document_id for d in docs):
        raise HTTPException(status_code=404, detail="Document not found")
    deleted = request.app.state.document_vault.delete(document_id)
    if not deleted:
        raise HTTPException(status_code=404, detail="Document not found")
    request.app.state.audit.record(
        "delete_document", user_id=getattr(user, "id", None),
        application_id=application_id,
    )
    return Response(status_code=204)


# --- Prosecution ---
@router.post("/applications/{application_id}/office-actions", tags=["prosecution"])
async def receive_office_action(
    request: Request,
    application_id: str,
    kind: OfficeActionKind = Query(...),
    body: str = Query(...),
    user: User = Depends(StaffUser),
):
    return request.app.state.prosecution.receive_office_action(application_id, kind, body)


# --- Status transition (director/MD shared power, domain lifecycle) ---
@router.post("/applications/{application_id}/status", tags=["applicationIntake"])
async def change_status(
    request: Request,
    application_id: str,
    new_status: ApplicationStatus = Query(...),
    staff: User = Depends(StaffUser),
):
    from app.modules.application_intake.local import InvalidTransitionError

    try:
        application = request.app.state.application_intake.change_status(
            application_id, new_status, changed_by=getattr(staff, "email", "staff")
        )
    except InvalidTransitionError as exc:
        raise HTTPException(status_code=422, detail=str(exc))
    if application is None:
        raise HTTPException(status_code=404, detail="Unknown application")
    warning: Optional[str] = None
    docs = request.app.state.document_vault.list_for_application(application_id)
    document_available = len(docs) > 0
    if new_status == ApplicationStatus.GRANTED and not document_available:
        # Non-blocking warning: grant stands, but director/MD must upload the document.
        warning = (
            f"GRANTED with no patent document for {application_id} — "
            "please upload the granted patent document."
        )
        request.app.state.audit.record(
            "grant_without_document",
            user_id=getattr(staff, "id", None),
            application_id=application_id,
        )
        request.app.state.notification.send_admin_warning(
            getattr(staff, "email", ""), application.id
        )
    # Notify the user on every status change (domain invariant).
    request.app.state.notification.send_status_change(
        application.inventor_email,
        application.id,
        new_status.value,
        document_available=document_available,
    )
    request.app.state.audit.record(
        f"status_change:{new_status.value}",
        user_id=getattr(staff, "id", None),
        application_id=application_id,
    )
    payload = application.model_dump(mode="json")
    payload["warning"] = warning
    return payload


# --- Portfolio Analytics ---
@router.get("/analytics/portfolio", tags=["portfolioAnalytics"])
async def portfolio_summary(request: Request, user: User = Depends(StaffUser)):
    return request.app.state.portfolio_analytics.portfolio_summary()


@router.get("/analytics/deadlines", tags=["portfolioAnalytics"])
async def deadline_report(request: Request, user: User = Depends(StaffUser)):
    return request.app.state.portfolio_analytics.deadline_report()


# --- Notifications (for current user) ---
@router.get("/notifications", tags=["notification"])
async def list_notifications(request: Request, user: User = Depends(CurrentUser)):
    return request.app.state.notification.get_for_recipient(user.email)


# --- Notification ---
@router.post("/notify/status-change", tags=["notification"])
async def notify_status_change(
    request: Request,
    recipient_email: str = Query(...),
    application_ref: str = Query(...),
    new_status: ApplicationStatus = Query(...),
    user: User = Depends(CurrentUser),
):
    return request.app.state.notification.send_status_change(
        recipient_email, application_ref, new_status.value
    )


# --- User Management (admin only) ---
@router.get("/users", tags=["authorization"])
async def list_users(request: Request, admin: User = Depends(AdminUser)):
    return request.app.state.authorization.list_users()


# --- Send notification to patent inventor (admin only) ---
@router.post("/applications/{application_id}/notify", tags=["notification"])
async def notify_inventor(
    request: Request,
    application_id: str,
    subject: str = Query(...),
    body: str = Query(...),
    admin: User = Depends(StaffUser),
):
    application = request.app.state.application_intake.get_application(application_id)
    if application is None:
        raise HTTPException(status_code=404, detail="Unknown application")
    inventors = request.app.state.application_intake.get_inventors_for_application(application_id)
    sent = []
    for inv in inventors:
        notification = request.app.state.notification.send_notification(
            inv.inventor_email, subject, body
        )
        sent.append(notification)
    request.app.state.audit.record(
        "send_notification",
        user_id=getattr(admin, "id", None),
        application_id=application_id,
    )
    return sent


# --- Filing Workflow (admin only) ---
from app.modules.application_intake.local import (
    InvalidTransitionError as _InvalidTransition,
)


def _filing_error(exc: ValueError) -> HTTPException:
    if isinstance(exc, _InvalidTransition):
        return HTTPException(status_code=422, detail=str(exc))
    return HTTPException(status_code=404, detail=str(exc))


@router.post("/admin/filing/{application_id}/file", tags=["filingWorkflow"])
async def filing_mark_filed(
    request: Request,
    application_id: str,
    admin: User = Depends(StaffUser),
):
    workflow = request.app.state.filing_workflow
    try:
        return workflow.mark_filed(application_id, getattr(admin, "email", "admin"))
    except ValueError as exc:
        raise _filing_error(exc)


@router.post("/admin/filing/{application_id}/acknowledge", tags=["filingWorkflow"])
async def filing_acknowledge_nipo(
    request: Request,
    application_id: str,
    admin: User = Depends(StaffUser),
):
    workflow = request.app.state.filing_workflow
    try:
        return workflow.acknowledge_nipo(application_id, getattr(admin, "email", "admin"))
    except ValueError as exc:
        raise _filing_error(exc)


@router.post("/admin/filing/{application_id}/defect-sheets", tags=["filingWorkflow"])
async def filing_record_defect_sheet(
    request: Request,
    application_id: str,
    sheet_number: int = Query(...),
    description: str = Query(...),
    admin: User = Depends(StaffUser),
):
    workflow = request.app.state.filing_workflow
    try:
        return workflow.record_defect_sheet(
            application_id, sheet_number, description,
            getattr(admin, "email", "admin"),
        )
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc))


@router.get("/admin/filing/{application_id}/defect-sheets", tags=["filingWorkflow"])
async def filing_list_defect_sheets(
    request: Request,
    application_id: str,
    admin: User = Depends(StaffUser),
):
    return request.app.state.filing_workflow.get_defect_sheets(application_id)


@router.post("/admin/filing/{application_id}/grant", tags=["filingWorkflow"])
async def filing_mark_granted(
    request: Request,
    application_id: str,
    patent_number: str = Query(...),
    admin: User = Depends(StaffUser),
):
    workflow = request.app.state.filing_workflow
    try:
        return workflow.mark_granted(
            application_id, patent_number, getattr(admin, "email", "admin"),
        )
    except ValueError as exc:
        raise _filing_error(exc)


@router.post("/admin/filing/{application_id}/reject", tags=["filingWorkflow"])
async def filing_mark_rejected(
    request: Request,
    application_id: str,
    admin: User = Depends(StaffUser),
):
    workflow = request.app.state.filing_workflow
    try:
        return workflow.mark_rejected(application_id, getattr(admin, "email", "admin"))
    except ValueError as exc:
        raise _filing_error(exc)


@router.get("/admin/filing/{application_id}/status", tags=["filingWorkflow"])
async def filing_status(
    request: Request,
    application_id: str,
    admin: User = Depends(StaffUser),
):
    record = request.app.state.filing_workflow.get_filing_record(application_id)
    if record is None:
        raise HTTPException(status_code=404, detail="No filing record found")
    return record

