"""Authorization - local stub adapter.

Issues and verifies a locally-signed JWT (PyJWT) with a dev-only secret.
Mirrors the better-auth JWT shape so the verification path is swappable.

Includes a minimal credential model so inventors can self-login (Readme flow):
passwords are stored/checked in plaintext here ONLY because this is a local
stub — production delegates auth (and secrets) to better-auth.
"""

from __future__ import annotations

import uuid
from datetime import datetime, timedelta, timezone
from typing import Optional

import jwt

from app.core.config import settings
from app.domain.common import Role
from app.modules.authorization.interface import AuthorizationModule
from app.modules.authorization.models import LoginRequest, RegisterRequest, Token, User

_ALG = "HS256"
_TOKEN_TTL_SECONDS = 60 * 60 * 12  # 12h for the local stub


class LocalAuthorizationModule:
    def __init__(self, secret: str = "") -> None:
        # Secret comes from settings (env BLITTO_AUTH_SECRET) in production.
        self._secret = secret or settings.auth_secret
        self._users: dict[str, User] = {}
        self._passwords: dict[str, str] = {}

    def register(self, request: RegisterRequest) -> User:
        if any(u.email == request.email for u in self._users.values()):
            raise ValueError(f"email already registered: {request.email}")
        user = User(
            id=str(uuid.uuid4()),
            email=request.email,
            role=request.role,
            user_code=request.user_code,
        )
        self._users[user.id] = user
        if request.password:
            self._passwords[user.id] = request.password
        return user

    def login(self, request: LoginRequest) -> Optional[Token]:
        user = next((u for u in self._users.values() if u.email == request.email), None)
        if user is None:
            return None
        if self._passwords.get(user.id) != request.password:
            return None
        return self._issue(user)

    def issue_token(self, email: str) -> Optional[Token]:
        user = next((u for u in self._users.values() if u.email == email), None)
        if user is None:
            return None
        return self._issue(user)

    def verify_token(self, token: str) -> Optional[User]:
        try:
            payload = jwt.decode(
                token, self._secret, algorithms=[_ALG], options={"require": ["exp"]}
            )
        except jwt.PyJWTError:
            return None
        sub = payload.get("sub")
        if not sub:
            return None
        return self._users.get(sub)

    def _issue(self, user: User) -> Token:
        payload = {
            "sub": user.id,
            "email": user.email,
            "role": user.role.value,
            "exp": datetime.now(timezone.utc) + timedelta(seconds=_TOKEN_TTL_SECONDS),
        }
        return Token(access_token=jwt.encode(payload, self._secret, algorithm=_ALG))
