"""Authorization - better-auth production adapter.

better-auth (https://github.com/better-auth/better-auth) is a TypeScript,
framework-agnostic auth provider with no native Python SDK. This adapter treats
better-auth as the auth server and verifies the JWTs it issues via its JWKS
endpoint (resource-server pattern). Token issuance/registration are handled by
the better-auth server, not this backend.

Wiring: set BLITTO_BETTER_AUTH_JWKS_URL (and issuer/audience) and select this
adapter in app/main.py instead of LocalAuthorizationModule for production.
"""

from __future__ import annotations

import time
from typing import Optional

import httpx
import jwt

from app.core.config import settings
from app.core.email_policy import is_institution_email
from app.modules.authorization.interface import AuthorizationModule
from app.modules.authorization.models import RegisterRequest, Token, User


class BetterAuthAuthorizationModule:
    def __init__(
        self,
        jwks_url: str = "",
        issuer: str = "",
        audience: str = "",
        http_timeout: float = 5.0,
    ) -> None:
        self._jwks_url = jwks_url or settings.better_auth_jwks_url
        self._issuer = issuer or settings.better_auth_issuer
        self._audience = audience or settings.better_auth_audience
        self._http_timeout = http_timeout
        self._jwks_cache: Optional[dict] = None
        self._jwks_fetched_at: float = 0.0

    def register(self, request: RegisterRequest) -> User:
        raise NotImplementedError(
            "User registration is handled by the better-auth server, not this backend."
        )

    def issue_token(self, email: str) -> Optional[Token]:
        raise NotImplementedError(
            "Token issuance is handled by the better-auth server."
        )

    def verify_token(self, token: str) -> Optional[User]:
        keys = self._get_jwks()
        if keys is None:
            return None
        try:
            header = jwt.get_unverified_header(token)
            key = next((k for k in keys["keys"] if k["kid"] == header.get("kid")), None)
            if key is None:
                return None
            public_key = jwt.algorithms.RSAAlgorithm.from_jwk(key)
            payload = jwt.decode(
                token,
                public_key,
                algorithms=["RS256"],
                audience=self._audience or None,
                issuer=self._issuer or None,
            )
        except jwt.PyJWTError:
            return None
        email = payload.get("email", "")
        if not is_institution_email(email):
            return None
        return User(
            id=payload.get("sub", ""),
            email=payload.get("email", ""),
            role=_coerce_role(payload),
            user_code=payload.get("user_code"),
        )

    def _get_jwks(self) -> Optional[dict]:
        now = time.time()
        if self._jwks_cache is not None and now - self._jwks_fetched_at < 3600:
            return self._jwks_cache
        try:
            resp = httpx.get(self._jwks_url, timeout=self._http_timeout)
            resp.raise_for_status()
            self._jwks_cache = resp.json()
            self._jwks_fetched_at = now
            return self._jwks_cache
        except httpx.HTTPError:
            return None


def _coerce_role(payload: dict) -> Role:
    from app.domain.common import Role

    raw = payload.get("role", "user")
    try:
        return Role(raw)
    except ValueError:
        return Role.USER
