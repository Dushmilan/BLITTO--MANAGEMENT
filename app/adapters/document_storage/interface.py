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
