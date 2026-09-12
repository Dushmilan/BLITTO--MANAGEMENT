"""Document storage adapter - PKI envelope encryption with RSA-4096 + AES-256-GCM.

Vault lifecycle:
  First run → generates RSA key pair + random 256-bit master key.
  Admin enters master key → decrypts RSA private key → 12-hour unlock window.
  Lock / expiry → private key reference dropped (reclaimed by GC; NOT secure zeroization).
"""

from __future__ import annotations

import base64
import binascii
import logging
import os
import re
import uuid
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Optional

from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.pbkdf2 import PBKDF2HMAC

from app.adapters.document_storage.interface import DocumentStoreAdapter

logger = logging.getLogger(__name__)

_AES_KEY_SIZE_BITS = 256
_AES_NONCE_SIZE = 12        # 96 bits (GCM standard)
_RSA_KEY_SIZE = 4096
_PBKDF2_ITERATIONS = 600_000
_SALT_SIZE = 16
_ENCRYPTED_KEY_FILE = "private_key.enc"
_PUBLIC_KEY_FILE = "public_key.pem"

_REF_RE = re.compile(r"[0-9a-f-]+\.enc")


def _generate_rsa_key_pair() -> rsa.RSAPrivateKey:
    return rsa.generate_private_key(public_exponent=65537, key_size=_RSA_KEY_SIZE)


def _derive_aes_key(master_key_bytes: bytes, salt: bytes) -> bytes:
    kdf = PBKDF2HMAC(
        algorithm=hashes.SHA256(),
        length=32,
        salt=salt,
        iterations=_PBKDF2_ITERATIONS,
    )
    return kdf.derive(master_key_bytes)


class VaultLockedError(PermissionError):
    """Raised when a store operation is attempted while the vault is locked."""


class PKIEncryptedStore:
    """Implements ``DocumentStoreAdapter`` with RSA/AES hybrid envelope encryption.

    On first construction, generates RSA key pair + random master key.
    The RSA private key is encrypted with the master key and persisted.
    The vault starts locked; call ``unlock(master_key)`` to open it.
    """

    def __init__(
        self,
        cert_dir: str,
        storage_dir: str,
        ttl_hours: int = 12,
    ) -> None:
        self._cert_dir = Path(cert_dir)
        self._storage_dir = Path(storage_dir)
        self._cert_dir.mkdir(parents=True, exist_ok=True)
        self._storage_dir.mkdir(parents=True, exist_ok=True)
        self._ttl_hours = ttl_hours

        self._private_key: Optional[rsa.RSAPrivateKey] = None
        self._unlocked_until: Optional[datetime] = None
        self.generated_master_key: Optional[str] = None
        self._public_key: Optional[rsa.RSAPublicKey] = None

        self._init_key_pair()

    # ------------------------------------------------------------------
    # Initialization
    # ------------------------------------------------------------------

    def _init_key_pair(self) -> None:
        """Load existing encrypted key or generate first-time keys."""
        enc_path = self._cert_dir / _ENCRYPTED_KEY_FILE
        pub_path = self._cert_dir / _PUBLIC_KEY_FILE

        if not enc_path.exists():
            self._first_time_init(enc_path, pub_path)
            return
        if not pub_path.exists():
            logger.error("cert dir half-initialized: %s missing, re-derive on unlock", pub_path)
            self._public_key = None
            return
        pub_pem = pub_path.read_bytes()
        self._public_key = serialization.load_pem_public_key(pub_pem)

    def _first_time_init(
        self, enc_path: Path, pub_path: Path
    ) -> None:
        """Generate RSA key pair + random master key. Private key encrypted on disk."""
        private_key = _generate_rsa_key_pair()
        self._public_key = private_key.public_key()

        # Generate random 256-bit master key.
        master_key_bytes = os.urandom(32)
        self.generated_master_key = base64.b64encode(master_key_bytes).decode()

        logger.warning(
            "First-run vault master key generated. Persist it securely "
            "(e.g. BLITTO_VAULT_MASTER_KEY secret); it will not be saved to disk."
        )

        # Write public key (plain).
        pub_pem = self._public_key.public_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PublicFormat.SubjectPublicKeyInfo,
        )
        pub_path.write_bytes(pub_pem)

        # Write encrypted private key.
        self._write_encrypted_private_key(private_key, master_key_bytes, enc_path)

        # Vault stays locked — admin must call unlock().
        self._private_key = None
        self._unlocked_until = None

    # ------------------------------------------------------------------
    # Encrypted private key persistence
    # ------------------------------------------------------------------

    @staticmethod
    def _write_encrypted_private_key(
        private_key: rsa.RSAPrivateKey,
        master_key_bytes: bytes,
        enc_path: Path,
    ) -> None:
        """Encrypt the RSA private key with *master_key_bytes* and write to disk."""
        private_pem = private_key.private_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PrivateFormat.PKCS8,
            encryption_algorithm=serialization.NoEncryption(),
        )

        salt = os.urandom(_SALT_SIZE)
        aes_key = _derive_aes_key(master_key_bytes, salt)
        nonce = os.urandom(_AES_NONCE_SIZE)
        aesgcm = AESGCM(aes_key)
        ciphertext = aesgcm.encrypt(nonce, private_pem, None)

        # Blob: [salt][nonce][ciphertext-with-tag]
        enc_path.write_bytes(salt + nonce + ciphertext)

    def _decrypt_private_key(self, master_key_bytes: bytes) -> Optional[rsa.RSAPrivateKey]:
        """Read encrypted private key from disk, decrypt with *master_key_bytes*."""
        enc_path = self._cert_dir / _ENCRYPTED_KEY_FILE
        if not enc_path.exists():
            return None

        try:
            blob = enc_path.read_bytes()
            if len(blob) < _SALT_SIZE + _AES_NONCE_SIZE:
                return None

            salt = blob[:_SALT_SIZE]
            nonce = blob[_SALT_SIZE : _SALT_SIZE + _AES_NONCE_SIZE]
            ciphertext = blob[_SALT_SIZE + _AES_NONCE_SIZE :]

            aes_key = _derive_aes_key(master_key_bytes, salt)
            aesgcm = AESGCM(aes_key)
            private_pem = aesgcm.decrypt(nonce, ciphertext, None)

            private_key = serialization.load_pem_private_key(private_pem, password=None)
            return private_key
        except InvalidTag:
            return None
        except (ValueError, OSError) as exc:
            logger.debug("Private key decryption failed: %s", exc)
            return None

    # ------------------------------------------------------------------
    # Vault unlock / lock
    # ------------------------------------------------------------------

    def unlock(self, master_key_b64: str) -> bool:
        """Unlock vault with base64-encoded *master_key*. Returns True on success."""
        try:
            master_key_bytes = base64.b64decode(master_key_b64, validate=True)
        except (ValueError, TypeError, binascii.Error):
            return False
        if len(master_key_bytes) != 32:
            return False

        private_key = self._decrypt_private_key(master_key_bytes)
        if private_key is None:
            return False

        self._private_key = private_key
        self._public_key = private_key.public_key()
        pub_path = self._cert_dir / _PUBLIC_KEY_FILE
        if not pub_path.exists():
            pub_pem = self._public_key.public_bytes(
                encoding=serialization.Encoding.PEM,
                format=serialization.PublicFormat.SubjectPublicKeyInfo,
            )
            pub_path.write_bytes(pub_pem)
        self._unlocked_until = datetime.now(timezone.utc) + timedelta(
            hours=self._ttl_hours,
        )
        return True

    def lock(self) -> None:
        """Lock vault: drop in-memory private key reference (GC reclaims; not secure zeroization)."""
        self._private_key = None
        self._unlocked_until = None

    def is_unlocked(self) -> bool:
        if self._private_key is None or self._unlocked_until is None:
            return False
        return datetime.now(timezone.utc) < self._unlocked_until

    def unlock_remaining(self) -> float:
        if not self.is_unlocked():
            return 0.0
        remaining = (self._unlocked_until - datetime.now(timezone.utc)).total_seconds()
        return max(0.0, remaining)

    def _require_unlocked_for_decrypt(self) -> None:
        if not self.is_unlocked():
            raise VaultLockedError("Vault is locked (decrypt requires unlock)")

    # Keep old _require_unlocked as alias to avoid breaking imports:
    _require_unlocked = _require_unlocked_for_decrypt

    # ------------------------------------------------------------------
    # DocumentStoreAdapter protocol
    # ------------------------------------------------------------------

    def _checked_blob_path(self, ref: str):
        if not _REF_RE.fullmatch(ref) or "/" in ref or "\\" in ref or ".." in ref:
            return None
        p = self._storage_dir / ref
        try:
            if p.resolve().parent != self._storage_dir.resolve():
                return None
        except OSError:
            return None
        return p

    def put(self, content: bytes) -> str:
        if self._public_key is None:
            raise VaultLockedError("Vault public key unavailable")
        file_id = str(uuid.uuid4())
        ref = f"{file_id}.enc"

        aes_key = AESGCM.generate_key(bit_length=_AES_KEY_SIZE_BITS)
        nonce = os.urandom(_AES_NONCE_SIZE)
        aesgcm = AESGCM(aes_key)
        ciphertext = aesgcm.encrypt(nonce, content, file_id.encode())

        wrapped_key = self._public_key.encrypt(
            aes_key,
            padding.OAEP(
                mgf=padding.MGF1(algorithm=hashes.SHA256()),
                algorithm=hashes.SHA256(),
                label=None,
            ),
        )

        blob_path = self._storage_dir / ref
        blob_path.write_bytes(
            b"V1" + len(wrapped_key).to_bytes(2, "big") + wrapped_key + nonce + ciphertext
        )
        return ref

    def get(self, ref: str) -> Optional[bytes]:
        self._require_unlocked_for_decrypt()

        blob_path = self._checked_blob_path(ref)
        if blob_path is None or not blob_path.exists():
            return None

        try:
            blob = blob_path.read_bytes()
            if blob[:2] == b"V1":
                wlen = int.from_bytes(blob[2:4], "big")
                wrapped_key = blob[4 : 4 + wlen]
                nonce = blob[4 + wlen : 4 + wlen + _AES_NONCE_SIZE]
                ciphertext = blob[4 + wlen + _AES_NONCE_SIZE :]
                aad = ref[:-4].encode()  # strip ".enc" -> file_id
            else:
                # legacy blob: infer wrapped size from current key (backward compat only)
                wlen = self._private_key.key_size // 8
                if len(blob) < wlen + _AES_NONCE_SIZE:
                    return None
                wrapped_key = blob[:wlen]
                nonce = blob[wlen : wlen + _AES_NONCE_SIZE]
                ciphertext = blob[wlen + _AES_NONCE_SIZE :]
                aad = None

            aes_key = self._private_key.decrypt(
                wrapped_key,
                padding.OAEP(
                    mgf=padding.MGF1(algorithm=hashes.SHA256()),
                    algorithm=hashes.SHA256(),
                    label=None,
                ),
            )

            aesgcm = AESGCM(aes_key)
            return aesgcm.decrypt(nonce, ciphertext, aad)

        except InvalidTag:
            return None
        except (ValueError, OSError) as exc:
            logger.debug("Decryption failed for %s: %s", ref, exc)
            return None

    def delete(self, ref: str) -> bool:
        self._require_unlocked_for_decrypt()

        blob_path = self._checked_blob_path(ref)
        if blob_path is None or not blob_path.exists():
            return False
        blob_path.unlink()
        return True
