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


async def require_md(request: Request) -> User:
    """Only the MD manages users and mints tokens for others."""
    user = await get_current_user(request)
    if user.role != Role.MD:
        raise HTTPException(status_code=403, detail="MD role required")
    return user


MDUser = require_md


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


async def require_staff(request: Request) -> User:
    """Director / MD shared powers (docketing, vault, analytics, prosecution).

    Users (confidentiality invariant #6) see only their own application
    status, never the internal docket/portfolio.
    """
    user = await get_current_user(request)
    if user.role not in (Role.DIRECTOR, Role.MD):
        raise HTTPException(status_code=403, detail="Director or MD role required")
    return user


StaffUser = require_staff

# Backwards-compatible aliases (prefer MDUser / StaffUser in new code).
AdminUser = require_md
AttorneyUser = require_role(Role.DIRECTOR, Role.MD)
