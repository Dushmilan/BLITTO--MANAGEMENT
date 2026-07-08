"""Email provider seam - local console/mock adapter."""

from __future__ import annotations

from app.adapters.email.interface import EmailProviderAdapter


class LocalEmailProvider:
    def send(self, to: str, subject: str, body: str) -> None:
        print(f"[email:mock] -> {to} | {subject}\n{body}")
