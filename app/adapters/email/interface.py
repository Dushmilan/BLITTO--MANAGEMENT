"""Email provider seam - adapter interface.

True-external seam per Agent.md: production = SendGrid/Resend/SMTP, test/local = mock.
"""

from __future__ import annotations

from typing import Protocol


class EmailProviderAdapter(Protocol):
    def send(self, to: str, subject: str, body: str) -> None:
        ...
