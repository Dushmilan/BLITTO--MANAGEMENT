"""Document storage seam - adapter interface.

Ports-and-adapters seam per Agent.md: production = S3, test/local = in-memory/file.
"""

from __future__ import annotations

from typing import Optional, Protocol


class DocumentStoreAdapter(Protocol):
    def put(self, content: bytes) -> str:
        """Store content, return a reference handle."""
        ...

    def get(self, ref: str) -> Optional[bytes]:
        ...

    def delete(self, ref: str) -> bool:
        """Remove content for *ref*. Returns True if removed, False if not found."""
        ...

    def unlock(self, master_key: str) -> bool:
        """Unlock vault with *master_key*. Returns True if successful."""
        ...

    def lock(self) -> None:
        """Lock vault immediately."""
        ...

    def is_unlocked(self) -> bool:
        """Return True if vault is unlocked and within TTL."""
        ...

    def unlock_remaining(self) -> float:
        """Seconds until auto-lock. 0 if locked or expired."""
        ...
