"""Authorization - domain models."""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Optional

from pydantic import BaseModel, Field

from app.domain.common import Role


def _now() -> datetime:
    return datetime.now(timezone.utc)


class User(BaseModel):
    id: str
    email: str
    role: Role
    user_code: Optional[str] = None
    created_at: datetime = Field(default_factory=_now)


class RegisterRequest(BaseModel):
    email: str
    role: Role = Role.INVENTOR
    user_code: Optional[str] = None
    # Local stub only: plaintext password. Production (better-auth) owns creds.
    password: Optional[str] = None


class LoginRequest(BaseModel):
    email: str
    password: str


class Token(BaseModel):
    access_token: str
    token_type: str = "bearer"
