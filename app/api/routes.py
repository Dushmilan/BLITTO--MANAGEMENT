"""HTTP routes for all modules (thin adapters over module interfaces)."""

from __future__ import annotations

from datetime import datetime
from typing import Optional

from fastapi import APIRouter, Depends, HTTPException, Query, Request

from app.api.deps import AdminUser, AttorneyUser, CurrentUser, StaffUser, User
from app.domain.common import ApplicationStatus, Role
from app.modules.application_intake.models import Disclosure
from app.modules.authorization.models import LoginRequest, RegisterRequest
from app.modules.docketing.models import DeadlineType
from app.modules.prosecution.models import OfficeActionKind
from app.modules.notification.models import Notification

router = APIRouter()


# --- Authorization ---
# Role-separation invariant #3: only admins manage users / issue tokens.
@router.post("/auth/register", tags=["authorization"])
async def auth_register(
    request: Request, body: RegisterRequest, user: User = Depends(CurrentUser)
):
    # Privilege check: only admins may create staff roles. Inventors self-register.
    if body.role != Role.INVENTOR and user.role != Role.ADMIN:
        raise HTTPException(status_code=403, detail="Only admins may create staff roles")
    try:
        return request.app.state.authorization.register(body)
    except ValueError as exc:
        raise HTTPException(status_code=409, detail=str(exc))


@router.post("/auth/token", tags=["authorization"])
async def auth_token(request: Request, admin: User = Depends(AdminUser)):
    # Issue a token for the authenticated admin only (no minting for arbitrary emails).
    token = request.app.state.authorization.issue_token(admin.email)
    if token is None:
        raise HTTPException(status_code=404, detail="Unknown email")
    return token


# Public login (Readme inventor self-login flow).
@router.post("/auth/login", tags=["authorization"])
async def auth_login(request: Request, body: LoginRequest):
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
    # Confidentiality invariant #6: inventors see only their own.
    applications = request.app.state.application_intake.list_applications()
    if user.role == Role.INVENTOR:
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
# Inventors have no upload capability (Readme): gate to admin/paralegal.
@router.post("/applications/{application_id}/documents", tags=["documentVault"])
async def store_document(
    request: Request,
    application_id: str,
    filename: str = Query(...),
    user: User = Depends(CurrentUser),
):
    if user.role not in (Role.ADMIN, Role.PARALEGAL):
        raise HTTPException(status_code=403, detail="Upload not permitted for this role")
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
    return document


@router.get("/applications/{application_id}/documents", tags=["documentVault"])
async def list_documents(
    request: Request, application_id: str, user: User = Depends(StaffUser)
):
    return request.app.state.document_vault.list_for_application(application_id)


# --- Prosecution ---
@router.post("/applications/{application_id}/office-actions", tags=["prosecution"])
async def receive_office_action(
    request: Request,
    application_id: str,
    kind: OfficeActionKind = Query(...),
    body: str = Query(...),
    user: User = Depends(AttorneyUser),
):
    return request.app.state.prosecution.receive_office_action(application_id, kind, body)


# --- Status transition (admin-only, domain lifecycle) ---
@router.post("/applications/{application_id}/status", tags=["applicationIntake"])
async def change_status(
    request: Request,
    application_id: str,
    new_status: ApplicationStatus = Query(...),
    admin: User = Depends(AdminUser),
):
    application = request.app.state.application_intake.change_status(
        application_id, new_status, changed_by=getattr(admin, "email", "admin")
    )
    if application is None:
        raise HTTPException(status_code=404, detail="Unknown application")
    # Notify the inventor on every status change (domain invariant).
    request.app.state.notification.send_status_change(
        application.inventor_email, application.id, new_status.value
    )
    request.app.state.audit.record(
        f"status_change:{new_status.value}",
        user_id=getattr(admin, "id", None),
        application_id=application_id,
    )
    return application


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


# --- Notifications (for current user) ---
@router.get("/notifications", tags=["notification"])
async def list_notifications(request: Request, user: User = Depends(CurrentUser)):
    return request.app.state.notification.get_for_recipient(user.email)


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
    admin: User = Depends(AdminUser),
):
    application = request.app.state.application_intake.get_application(application_id)
    if application is None:
        raise HTTPException(status_code=404, detail="Unknown application")
    notification = request.app.state.notification.send_notification(
        application.inventor_email, subject, body
    )
    request.app.state.audit.record(
        "send_notification",
        user_id=getattr(admin, "id", None),
        application_id=application_id,
    )
    return notification

