"""Document Vault - module interface.

Deep module per Agent.md: `documentVault` (ports-and-adapters, priority 1).
Production uses document storage seam (S3/file); local uses in-memory/file.
"""

from __future__ import annotations

from typing import Optional, Protocol

from app.modules.document_vault.models import Document


class DocumentVaultModule(Protocol):
    def store(
        self, application_id: str, filename: str, content: bytes, uploaded_by: str
    ) -> Document:
        ...

    def retrieve(self, document_id: str) -> Optional[bytes]:
        ...

    def list_for_application(self, application_id: str) -> list[Document]:
        ...

    def delete(self, document_id: str) -> bool:
        """Remove document by *document_id*. Returns True if removed, False if not found."""
        ...

    # --- Vault lifecycle ---

    def unlock(self, master_key: str) -> bool:
        ...

    def lock(self) -> None:
        ...

    def is_unlocked(self) -> bool:
        ...

    def unlock_remaining(self) -> float:
        ...
