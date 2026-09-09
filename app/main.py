"""BLITTO Patent Management System - FastAPI application entrypoint."""

import asyncio
from contextlib import asynccontextmanager

from fastapi import FastAPI

from app.core.config import settings
from app.core.logging import configure_logging
from app.domain.common import Role
from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.audit.local import LocalAuditModule
from app.modules.authorization.local import LocalAuthorizationModule
from app.modules.authorization.models import RegisterRequest
from app.modules.docketing.local import LocalDocketingModule
from app.modules.document_vault.local import LocalDocumentVaultModule
from app.modules.filing_workflow.local import LocalFilingWorkflowModule
from app.modules.notification.local import LocalNotificationModule
from app.modules.portfolio_analytics.local import LocalPortfolioAnalyticsModule
from app.modules.prosecution.local import LocalProsecutionModule
from app.api import router as api_router
from scripts.seed import seed_demo_data


@asynccontextmanager
async def lifespan(app: FastAPI):
    configure_logging()
    # Wire module instances (local adapters) into application state.
    app.state.application_intake = LocalApplicationIntakeModule()
    app.state.docketing = LocalDocketingModule()
    app.state.document_vault = LocalDocumentVaultModule()
    app.state.prosecution = LocalProsecutionModule(
        docketing=app.state.docketing,
        document_vault=app.state.document_vault,
    )
    app.state.authorization = LocalAuthorizationModule()
    app.state.notification = LocalNotificationModule()
    app.state.audit = LocalAuditModule()
    app.state.filing_workflow = LocalFilingWorkflowModule(
        application_intake=app.state.application_intake,
        notification=app.state.notification,
        audit=app.state.audit,
    )
    app.state.portfolio_analytics = LocalPortfolioAnalyticsModule(
        application_intake=app.state.application_intake,
        docketing=app.state.docketing,
    )
    _bootstrap_admin(app.state.authorization)
    _seed_demo_data(app.state)
    task = asyncio.create_task(_nipo_follow_up_scheduler(app))
    yield
    task.cancel()


async def _nipo_follow_up_scheduler(app: FastAPI) -> None:
    """Periodically notify admins about filed-but-unacknowledged patents."""
    interval = settings.nipo_follow_up_interval_hours * 3600
    while True:
        await asyncio.sleep(interval)
        try:
            apps = app.state.filing_workflow.get_filed_unacknowledged_apps()
            if not apps:
                continue
            admins = [
                u for u in app.state.authorization.list_users()
                if u.role in (Role.MD, Role.DIRECTOR)
            ]
            if not admins:
                continue
            for app_model, record in apps:
                for admin in admins:
                    app.state.notification.send_notification(
                        admin.email,
                        f"NIPO Follow-up Required: {app_model.title}",
                        f"Patent {app_model.title} ({app_model.application_number or app_model.id[:8]}) "
                        f"was filed on {record.filed_date.strftime('%Y-%m-%d')} "
                        f"but has not yet been acknowledged by NIPO. "
                        f"Please contact NIPO for follow-up.",
                    )
        except Exception:
            pass


def _bootstrap_admin(auth) -> None:
    """Create a default MD so the system is usable out-of-box (Readme flow).

    Creds come from env: BLITTO_BOOTSTRAP_ADMIN_EMAIL / _PASSWORD.
    Skipped if already set or env not provided.
    """
    email = settings.bootstrap_admin_email
    password = settings.bootstrap_admin_password
    if not email or not password:
        return
    from app.core.email_policy import is_institution_email

    if not is_institution_email(email):
        raise ValueError(
            f"Bootstrap admin email must be an institution address: {email}"
        )
    if any(u.email == email for u in auth._users.values()):
        return
    # register() stores the password when provided, so the MD can log in.
    auth.register(
        RegisterRequest(email=email, role=Role.MD, password=password)
    )


def _seed_demo_data(state) -> None:
    """Seed demo inventors and patent applications on first startup.

    Only runs if fewer than 2 users exist (i.e. just the bootstrap admin or none).
    """
    if len(state.authorization._users) > 1:
        return
    seed_demo_data(
        state.authorization,
        state.application_intake,
        state.prosecution,
        state.notification,
        state.filing_workflow,
    )


app = FastAPI(
    title="BLITTO Patent Management System",
    version="0.1.0",
    description="Local, no-DB FastAPI skeleton. Modules follow the deep-module "
    "architecture from Agent.md; graphify manages the architecture graph.",
    lifespan=lifespan,
)

app.include_router(api_router)
app.include_router(api_router, prefix="/api")


@app.get("/health", tags=["system"])
async def health() -> dict[str, str]:
    return {"status": "ok", "service": settings.service_name}
