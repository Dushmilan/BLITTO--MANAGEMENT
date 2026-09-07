"""BLITTO Patent Management System - FastAPI application entrypoint."""

from contextlib import asynccontextmanager

from fastapi import FastAPI

from app.core.config import settings
from app.core.logging import configure_logging
from app.domain.common import Role
from app.modules.application_intake.local import LocalApplicationIntakeModule
from app.modules.audit.local import LocalAuditModule
from app.modules.authorization.local import LocalAuthorizationModule
from app.modules.authorization.models import RegisterRequest
from app.modules.document_vault.local import LocalDocumentVaultModule
from app.modules.docketing.local import LocalDocketingModule
from app.modules.notification.local import LocalNotificationModule
from app.modules.portfolio_analytics.local import LocalPortfolioAnalyticsModule
from app.modules.prosecution.local import LocalProsecutionModule
from app.api import router as api_router


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
    app.state.portfolio_analytics = LocalPortfolioAnalyticsModule(
        application_intake=app.state.application_intake,
        docketing=app.state.docketing,
    )
    _bootstrap_admin(app.state.authorization)
    yield


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


app = FastAPI(
    title="BLITTO Patent Management System",
    version="0.1.0",
    description="Local, no-DB FastAPI skeleton. Modules follow the deep-module "
    "architecture from Agent.md; graphify manages the architecture graph.",
    lifespan=lifespan,
)

app.include_router(api_router)


@app.get("/health", tags=["system"])
async def health() -> dict[str, str]:
    return {"status": "ok", "service": settings.service_name}
