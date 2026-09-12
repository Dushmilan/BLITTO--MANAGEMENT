"""Document storage seam - plaintext dev-only adapter. Never use for real documents."""

from __future__ import annotations

import uuid
from typing import Optional

from app.adapters.document_storage.interface import DocumentStoreAdapter


class LocalDocumentStore:
    def __init__(self) -> None:
        self._data: dict[str, bytes] = {}
        self._locked = False

    def put(self, content: bytes) -> str:
        ref = f"mem:{uuid.uuid4()}"
        self._data[ref] = content
        return ref

    def get(self, ref: str) -> Optional[bytes]:
        return self._data.get(ref)

    def delete(self, ref: str) -> bool:
        if ref not in self._data:
            return False
        del self._data[ref]
        return True

    def unlock(self, master_key: str) -> bool:
        self._locked = False
        return True  # dev bypass: any key unlocks; NOT a security boundary

    def lock(self) -> None:
        self._locked = True

    def is_unlocked(self) -> bool:
        return not self._locked

    def unlock_remaining(self) -> float:
        return 0.0  # no expiry concept for plaintext dev store
