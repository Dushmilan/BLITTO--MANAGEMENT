"""Authorization - module interface.

Deep module per Agent.md: `authorization` (local-substitutable, priority 4).
Production adapter integrates better-auth (JWT/JWKS verification); local stub
issues+verifies its own dev token so the skeleton runs with no external service.
"""

from __future__ import annotations

from typing import Optional, Protocol

from app.modules.authorization.models import LoginRequest, RegisterRequest, Token, User


class AuthorizationModule(Protocol):
    def register(self, request: RegisterRequest) -> User:
        ...

    def login(self, request: LoginRequest) -> Optional[Token]:
        ...

    def issue_token(self, email: str) -> Optional[Token]:
        ...

    def verify_token(self, token: str) -> Optional[User]:
        ...

    def list_users(self) -> list[User]:
        ...
