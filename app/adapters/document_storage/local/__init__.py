"""Document storage seam - local in-memory adapter (test/production-local)."""

from __future__ import annotations

import uuid
from typing import Optional

from app.adapters.document_storage.interface import DocumentStoreAdapter


class LocalDocumentStore:
    def __init__(self) -> None:
        self._data: dict[str, bytes] = {}

    def put(self, content: bytes) -> str:
        ref = f"mem:{uuid.uuid4()}"
        self._data[ref] = content
        return ref

    def get(self, ref: str) -> Optional[bytes]:
        return self._data.get(ref)
