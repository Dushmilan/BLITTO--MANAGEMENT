"""Document Vault - local in-memory adapter (uses document storage seam)."""

from __future__ import annotations

import uuid
from typing import Optional

from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
from app.adapters.document_storage.interface import DocumentStoreAdapter
from app.adapters.document_storage.local import LocalDocumentStore
from app.modules.document_vault.interface import DocumentVaultModule
from app.modules.document_vault.models import Document


class LocalDocumentVaultModule:
    def __init__(self, store: Optional[DocumentStoreAdapter] = None) -> None:
        self._store = store or LocalDocumentStore()
        self._index: dict[str, Document] = {}

    def store(
        self, application_id: str, filename: str, content: bytes, uploaded_by: str
    ) -> Document:
        ref = self._store.put(content)
        document = Document(
            id=str(uuid.uuid4()),
            application_id=application_id,
            filename=filename,
            file_size=len(content),
            content_ref=ref,
            uploaded_by=uploaded_by,
        )
        self._index[document.id] = document
        return document

    def retrieve(self, document_id: str) -> Optional[bytes]:
        document = self._index.get(document_id)
        if document is None:
            return None
        return self._store.get(document.content_ref)

    def list_for_application(self, application_id: str) -> list[Document]:
        return [d for d in self._index.values() if d.application_id == application_id]

    def delete(self, document_id: str) -> bool:
        document = self._index.pop(document_id, None)
        if document is None:
            return False
        self._store.delete(document.content_ref)
        return True

    # --- Vault lifecycle ---

    def unlock(self, master_key: str) -> bool:
        return self._store.unlock(master_key)

    def lock(self) -> None:
        self._store.lock()

    def is_unlocked(self) -> bool:
        return self._store.is_unlocked()

    def unlock_remaining(self) -> float:
        return self._store.unlock_remaining()
