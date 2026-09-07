"""Authorization - domain models."""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Optional

from pydantic import BaseModel, Field, field_validator

from app.core.email_policy import institution_error, is_institution_email, normalize_email
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
    role: Role = Role.USER
    user_code: Optional[str] = None
    # Local stub only: plaintext password. Production (better-auth) owns creds.
    password: Optional[str] = None

    @field_validator("email")
    @classmethod
    def _institution_mail_only(cls, v: str) -> str:
        v = normalize_email(v)
        if not is_institution_email(v):
            raise ValueError(institution_error())
        return v


class LoginRequest(BaseModel):
    email: str
    password: str

    @field_validator("email")
    @classmethod
    def _institution_mail_only(cls, v: str) -> str:
        v = normalize_email(v)
        if not is_institution_email(v):
            raise ValueError(institution_error())
        return v


class Token(BaseModel):
    access_token: str
    token_type: str = "bearer"
