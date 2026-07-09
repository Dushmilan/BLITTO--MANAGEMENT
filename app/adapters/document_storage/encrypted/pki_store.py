"""Document storage adapter - PKI envelope encryption with RSA-4096 + AES-256-GCM.

Each document is encrypted with a unique AES key; the AES key is wrapped
with the RSA public key (OAEP-SHA256).  Encrypted blobs are persisted to
the configured storage directory as <uuid>.enc files.
"""

from __future__ import annotations

import logging
import os
import uuid
from pathlib import Path
from typing import Optional

from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

from app.adapters.document_storage.interface import DocumentStoreAdapter

logger = logging.getLogger(__name__)

_AES_KEY_SIZE_BITS = 256
_AES_NONCE_SIZE = 12        # 96 bits (GCM standard)
_RSA_KEY_SIZE = 4096


def _generate_rsa_key_pair() -> rsa.RSAPrivateKey:
    return rsa.generate_private_key(public_exponent=65537, key_size=_RSA_KEY_SIZE)


class PKIEncryptedStore:
    """Implements ``DocumentStoreAdapter`` with RSA/AES hybrid envelope encryption.

    On construction the adapter loads the RSA key pair from *cert_dir*.
    If the key pair does not exist it is generated and written to disk.
    """

    def __init__(self, cert_dir: str, storage_dir: str) -> None:
        self._cert_dir = Path(cert_dir)
        self._storage_dir = Path(storage_dir)
        self._cert_dir.mkdir(parents=True, exist_ok=True)
        self._storage_dir.mkdir(parents=True, exist_ok=True)

        self._private_key = self._load_or_generate_key_pair()

    # ------------------------------------------------------------------
    # Key management
    # ------------------------------------------------------------------

    def _load_or_generate_key_pair(self) -> rsa.RSAPrivateKey:
        private_path = self._cert_dir / "private_key.pem"
        public_path = self._cert_dir / "public_key.pem"

        try:
            return self._load_private_key(private_path)
        except FileNotFoundError:
            private_key = _generate_rsa_key_pair()
            self._write_key_pair(private_key, private_path, public_path)
            return private_key

    @staticmethod
    def _load_private_key(path: Path) -> rsa.RSAPrivateKey:
        pem = path.read_bytes()
        return serialization.load_pem_private_key(pem, password=None)

    @staticmethod
    def _write_key_pair(
        private_key: rsa.RSAPrivateKey,
        private_path: Path,
        public_path: Path,
    ) -> None:
        private_pem = private_key.private_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PrivateFormat.PKCS8,
            encryption_algorithm=serialization.NoEncryption(),
        )
        public_pem = private_key.public_key().public_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PublicFormat.SubjectPublicKeyInfo,
        )
        private_path.write_bytes(private_pem)
        public_path.write_bytes(public_pem)
        try:
            os.chmod(private_path, 0o600)
        except (OSError, AttributeError) as exc:
            logger.warning(
                "Could not restrict private key file permissions (%s). "
                "On Windows, use NTFS ACLs to protect the key.",
                exc,
            )

    # ------------------------------------------------------------------
    # DocumentStoreAdapter protocol
    # ------------------------------------------------------------------

    def put(self, content: bytes) -> str:
        """Encrypt *content* and persist to storage. Returns the file reference."""
        file_id = str(uuid.uuid4())
        ref = f"{file_id}.enc"

        # 1. Generate per-document AES key and nonce.
        aes_key = AESGCM.generate_key(bit_length=_AES_KEY_SIZE_BITS)
        nonce = os.urandom(_AES_NONCE_SIZE)

        # 2. Encrypt content with AES-256-GCM.
        aesgcm = AESGCM(aes_key)
        ciphertext = aesgcm.encrypt(nonce, content, None)

        # 3. Wrap the AES key with the RSA public key (OAEP-SHA256).
        wrapped_key = self._private_key.public_key().encrypt(
            aes_key,
            padding.OAEP(
                mgf=padding.MGF1(algorithm=hashes.SHA256()),
                algorithm=hashes.SHA256(),
                label=None,
            ),
        )

        # 4. Write blob: [wrapped_key][nonce][ciphertext-with-tag].
        blob_path = self._storage_dir / ref
        blob_path.write_bytes(wrapped_key + nonce + ciphertext)

        return ref

    def get(self, ref: str) -> Optional[bytes]:
        """Read, unwrap, and decrypt the blob for *ref*. Returns ``None`` on failure."""
        blob_path = self._storage_dir / ref
        if not blob_path.exists():
            return None

        try:
            blob = blob_path.read_bytes()

            wrapped_key_size = self._private_key.key_size // 8  # 512
            if len(blob) < wrapped_key_size + _AES_NONCE_SIZE:
                return None

            wrapped_key = blob[:wrapped_key_size]
            nonce = blob[wrapped_key_size : wrapped_key_size + _AES_NONCE_SIZE]
            ciphertext = blob[wrapped_key_size + _AES_NONCE_SIZE :]

            # 1. Unwrap AES key with RSA private key.
            aes_key = self._private_key.decrypt(
                wrapped_key,
                padding.OAEP(
                    mgf=padding.MGF1(algorithm=hashes.SHA256()),
                    algorithm=hashes.SHA256(),
                    label=None,
                ),
            )

            # 2. Decrypt content with AES-256-GCM.
            aesgcm = AESGCM(aes_key)
            return aesgcm.decrypt(nonce, ciphertext, None)

        except InvalidTag:
            return None
        except (ValueError, OSError) as exc:
            logger.debug("Decryption failed for %s: %s", ref, exc)
            return None
