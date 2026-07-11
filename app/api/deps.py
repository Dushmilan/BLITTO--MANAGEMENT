"""FastAPI dependencies."""

from __future__ import annotations

from fastapi import Depends, HTTPException, Request

from app.domain.common import Role
from app.modules.authorization.models import User


async def get_current_user(request: Request) -> User:
    auth = request.app.state.authorization
    header = request.headers.get("Authorization", "")
    if not header.startswith("Bearer "):
        raise HTTPException(status_code=401, detail="Missing bearer token")
    token = header[len("Bearer "):]
    user = auth.verify_token(token)
    if user is None:
        raise HTTPException(status_code=401, detail="Invalid or expired token")
    return user


CurrentUser = get_current_user


async def require_admin(request: Request) -> User:
    """Role-separation (CONTEXT.md RBAC): only admins manage users/auth."""
    user = await get_current_user(request)
    if user.role != Role.ADMIN:
        raise HTTPException(status_code=403, detail="Admin role required")
    return user


AdminUser = require_admin


def require_role(*roles: Role):
    """RBAC gate: only the listed roles may call the endpoint."""

    async def _gate(request: Request) -> User:
        user = await get_current_user(request)
        if user.role not in roles:
            raise HTTPException(
                status_code=403,
                detail=f"Required role(s): {', '.join(r.value for r in roles)}",
            )
        return user

    return _gate


# Office actions are prosecuted by attorneys only (CONTEXT.md RBAC).
AttorneyUser = require_role(Role.ATTORNEY)


async def require_staff(request: Request) -> User:
    """Internal staff (admin / attorney / paralegal) for docketing & analytics.

    Inventors (confidentiality invariant #6) see only their own application
    status, never the internal docket/portfolio.
    """
    user = await get_current_user(request)
    if user.role not in (Role.ADMIN, Role.ATTORNEY, Role.PARALEGAL):
        raise HTTPException(status_code=403, detail="Staff role required")
    return user


StaffUser = require_staff


async def require_unlocked_vault(request: Request) -> None:
    """Vault lock gate: raises 423 if the vault is locked."""
    vault = request.app.state.document_vault
    if not vault.is_unlocked():
        raise HTTPException(status_code=423, detail="Vault is locked")


UnlockedVault = require_unlocked_vault

