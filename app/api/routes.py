"""HTTP routes for all modules (thin adapters over module interfaces)."""

from __future__ import annotations

import io
import zipfile
from datetime import datetime, timezone
from typing import Optional

from fastapi import APIRouter, Depends, HTTPException, Query, Request, Response

from app.api.deps import CurrentUser, MDUser, StaffUser, User
from app.core.config import settings
from app.core.email_policy import is_institution_email
from app.domain.common import ApplicationStatus, Role
from app.modules.application_intake.models import Disclosure
from app.modules.authorization.models import LoginRequest, RegisterRequest
from app.modules.docketing.models import DeadlineType
from app.modules.prosecution.models import OfficeActionKind

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


# --- Document Vault ---
# Users have no upload capability (Readme): gate to director/MD.
@router.post("/applications/{application_id}/documents", tags=["documentVault"])
async def store_document(
    request: Request,
    application_id: str,
    filename: str = Query(...),
    user: User = Depends(CurrentUser),
):
    if user.role not in (Role.DIRECTOR, Role.MD):
        raise HTTPException(status_code=403, detail="Upload not permitted for this role")
    if request.app.state.application_intake.get_application(application_id) is None:
        raise HTTPException(status_code=404, detail="Unknown application")
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
    application = request.app.state.application_intake.change_status(
        application_id, new_status, changed_by=getattr(staff, "email", "staff")
    )
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


# --- Portfolio Analytics ---
@router.get("/analytics/portfolio", tags=["portfolioAnalytics"])
async def portfolio_summary(request: Request, user: User = Depends(StaffUser)):
    return request.app.state.portfolio_analytics.portfolio_summary()


@router.get("/analytics/deadlines", tags=["portfolioAnalytics"])
async def deadline_report(request: Request, user: User = Depends(StaffUser)):
    return request.app.state.portfolio_analytics.deadline_report()

