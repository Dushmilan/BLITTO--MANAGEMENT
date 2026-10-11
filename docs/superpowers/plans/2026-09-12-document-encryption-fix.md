# Document Encryption Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the 12 caveman-review findings on the document-encryption process so PKI envelope encryption is actually effective and safe by default.

**Architecture:** Keep the `DocumentStoreAdapter` seam unchanged. Harden `PKIEncryptedStore` in place (no auto-persisted master key, validated refs, versioned blobs, public-key-only encrypt), then wire it as the env-selectable default in `app/main.py` while keeping `LocalDocumentStore` as explicit dev-only plaintext.

**Tech Stack:** Python >=3.10, FastAPI, `cryptography` (AESGCM, RSA-OAEP-SHA256, PBKDF2HMAC-SHA256), pytest / pytest-cov.

**Spec:** Caveman-review 2026-09-12 on `app/adapters/document_storage/encrypted/pki_store.py:1-312`, `app/adapters/document_storage/local/__init__.py:1-41`, `app/modules/document_vault/local/__init__.py:1-63`, `app/main.py:31`, `app/api/routes.py:135-150`, `Readme.md:84-96` (issue #49: default is plaintext).

## Global Constraints

- TDD red-green-refactor, one failing test at a time through public interfaces (`put`/`get`/`unlock`/`lock`/`is_unlocked`, HTTP routes).
- `pytest` must pass after every task; coverage gate `pytest --cov=app --cov-report=term-missing -q` stays >= 80%.
- No new production dependencies (no slowapi — rate-limit with stdlib `time` + dict).
- Vault errors stay `VaultLockedError(PermissionError)` → HTTP 423 via existing `require_unlocked_vault`.
- Blob format change must stay backward-readable for old blobs (no version prefix) for one release.

---

### Task 1: Stop auto-persisting master key to `.env`

**Files:**
- Modify: `app/adapters/document_storage/encrypted/pki_store.py:106-132`
- Modify: `app/core/config.py:42-43`
- Test: `tests/test_document_vault.py` (append new tests)

**Interfaces:**
- Consumes: `settings.vault_master_key: str` (env `BLITTO_VAULT_MASTER_KEY`, empty = first-run).
- Produces: `PKIEncryptedStore.generated_master_key: Optional[str]` — set ONLY on first-run in-memory, never written to disk by the store; `_save_master_key_to_env()` deleted.

- [ ] **Step 1: Write the failing test**

```python
def test_pki_does_not_write_env_file(tmp_path, monkeypatch) -> None:
    monkeypatch.chdir(tmp_path)
    (tmp_path / ".env").write_text("EXISTING=1\n", encoding="utf-8")
    from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
    store = PKIEncryptedStore(
        cert_dir=str(tmp_path / "certs"),
        storage_dir=str(tmp_path / "docs"),
    )
    assert store.generated_master_key is not None
    content = (tmp_path / ".env").read_text(encoding="utf-8")
    assert "BLITTO_VAULT_MASTER_KEY" not in content
    assert content.strip() == "EXISTING=1"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_document_vault.py::test_pki_does_not_write_env_file -v`
Expected: FAIL (current code appends `BLITTO_VAULT_MASTER_KEY=...` to `.env`).

- [ ] **Step 3: Write minimal implementation**

```python
# app/adapters/document_storage/encrypted/pki_store.py
# DELETE _save_master_key_to_env() entirely (lines 106-118).
# In _first_time_init(), replace:
#   self._save_master_key_to_env(self.generated_master_key)
# with:
    logger.warning(
        "First-run vault master key generated. Persist it securely "
        "(e.g. BLITTO_VAULT_MASTER_KEY secret); it will not be saved to disk."
    )
# In _init_key_pair(), DELETE lines 102-104:
#   if settings.vault_master_key and not self.generated_master_key:
#       self.generated_master_key = settings.vault_master_key
# Reason: settings key must only be used via explicit unlock(), never
# auto-exposed as generated_master_key.
```

Also remove now-unused import if `settings` becomes unused in that file — keep the import if still referenced elsewhere, else delete `from app.core.config import settings`.

- [ ] **Step 4: Run test to verify it passes**

Run: `pytest tests/test_document_vault.py::test_pki_does_not_write_env_file tests/test_document_vault.py -v`
Expected: PASS (all 20+ vault tests still pass).

- [ ] **Step 5: Commit**

```bash
git add app/adapters/document_storage/encrypted/pki_store.py tests/test_document_vault.py
git commit -m "fix(vault): stop auto-persisting master key to .env"
```

---

### Task 2: Crash-proof startup + strict unlock key check

**Files:**
- Modify: `app/adapters/document_storage/encrypted/pki_store.py:89-104,205-221`
- Test: `tests/test_document_vault.py` (append)

**Interfaces:**
- Consumes: `PKIEncryptedStore._decrypt_private_key(master_key_bytes: bytes)` from Task 1.
- Produces: `unlock(master_key_b64: str) -> bool` returns `False` fast on bad base64 or decoded length != 32; `_init_key_pair()` never raises `FileNotFoundError` on half-initialized `cert_dir`.

- [ ] **Step 1: Write the failing tests**

```python
def test_pki_init_with_enc_but_missing_pub_does_not_crash(tmp_path, monkeypatch) -> None:
    monkeypatch.chdir(tmp_path)
    from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
    s1 = PKIEncryptedStore(cert_dir=str(tmp_path / "c"), storage_dir=str(tmp_path / "d1"))
    key = s1.generated_master_key
    assert key is not None
    (tmp_path / "c" / "public_key.pem").unlink()
    s2 = PKIEncryptedStore(cert_dir=str(tmp_path / "c"), storage_dir=str(tmp_path / "d2"))
    assert s2.unlock(key) is True


def test_pki_unlock_rejects_wrong_length_key_fast(tmp_path, monkeypatch) -> None:
    monkeypatch.chdir(tmp_path)
    import base64
    from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
    store = PKIEncryptedStore(cert_dir=str(tmp_path / "c"), storage_dir=str(tmp_path / "d"))
    short = base64.b64encode(b"too-short").decode()
    assert store.unlock(short) is False
    assert store.is_unlocked() is False
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pytest tests/test_document_vault.py::test_pki_init_with_enc_but_missing_pub_does_not_crash tests/test_document_vault.py::test_pki_unlock_rejects_wrong_length_key_fast -v`
Expected: FAIL — first raises `FileNotFoundError` on `pub_path.read_bytes()`, second runs full PBKDF2 then returns False (still passes logically but slow; after fix it short-circuits — assert timing not needed, length gate is the behavior change; second test currently PASSES so rewrite as regression lock: it must keep passing after adding the `len != 32` early-return).

- [ ] **Step 3: Write minimal implementation**

```python
# _init_key_pair replacement (lines 89-100):
def _init_key_pair(self) -> None:
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

# unlock() head (line 205):
def unlock(self, master_key_b64: str) -> bool:
    try:
        master_key_bytes = base64.b64decode(master_key_b64, validate=True)
    except (ValueError, TypeError, binascii.Error):
        return False
    if len(master_key_bytes) != 32:
        return False
    # ... rest unchanged, plus:
    # after self._public_key = private_key.public_key(), also persist pub pem
    # if it was missing:
    #   pub_path = self._cert_dir / _PUBLIC_KEY_FILE
    #   if not pub_path.exists(): pub_path.write_bytes(...SubjectPublicKeyInfo...)
```

Add `import binascii` at top.

- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest tests/test_document_vault.py -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/adapters/document_storage/encrypted/pki_store.py tests/test_document_vault.py
git commit -m "fix(vault): tolerate missing pubkey, reject bad-length unlock keys"
```

---

### Task 3: Ref validation + versioned blob + AAD binding

**Files:**
- Modify: `app/adapters/document_storage/encrypted/pki_store.py:246-303`
- Test: `tests/test_document_vault.py` (append)

**Interfaces:**
- Consumes: `put(content: bytes) -> str`, `get(ref: str) -> Optional[bytes]` signatures unchanged.
- Produces: New blobs prefixed `b"V1"` + `file_id` bound as AES-GCM AAD; `get()` accepts old unversioned blobs AND new `V1` blobs; `get()/delete()` reject `ref` with path separators or `..`.

- [ ] **Step 1: Write the failing tests**

```python
import re
def test_pki_ref_is_safe_filename(pki_store) -> None:
    ref = pki_store.put(b"hello")
    assert re.fullmatch(r"[0-9a-f-]+\.enc", ref), ref
    assert pki_store.get("../private_key.enc") is None
    assert pki_store.delete("../../etc/passwd.enc") is False


def test_pki_blob_binds_file_id(pki_store) -> None:
    ref = pki_store.put(b"bind me")
    blob = (pki_store._storage_dir / ref).read_bytes()
    assert blob[:2] == b"V1"
    # Copy ref1's whole blob to ref2's path. It is a valid ciphertext for
    # file_id1, but get(ref2) uses file_id2 as AAD -> must fail.
    # Without AAD binding this decrypts successfully (returns b"bind me").
    ref2 = pki_store.put(b"other content here!!")
    (pki_store._storage_dir / ref2).write_bytes(blob)
    assert pki_store.get(ref2) is None
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pytest tests/test_document_vault.py::test_pki_ref_is_safe_filename tests/test_document_vault.py::test_pki_blob_binds_file_id -v`
Expected: FAIL — `get("../private_key.enc")` currently probes parent dir (returns None only by accident, `delete` may return False but no validation), blob has no `V1` prefix.

- [ ] **Step 3: Write minimal implementation**

```python
import re
_REF_RE = re.compile(r"[0-9a-fA-F-]+\.enc")

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

# V1 blob format: b"V1" + len(wrapped_key).to_bytes(2,"big") + wrapped_key
#                 + nonce + ciphertext. Length is explicit -> never infer from key_size.
# put(): after file_id/ref created:
#   aesgcm.encrypt(nonce, content, file_id.encode())
#   blob_path.write_bytes(
#       b"V1" + len(wrapped_key).to_bytes(2, "big") + wrapped_key + nonce + ciphertext
#   )
# get(): resolve path via _checked_blob_path (None -> return None);
#   blob = path.read_bytes()
#   if blob[:2] == b"V1":
#       wlen = int.from_bytes(blob[2:4], "big")
#       wrapped_key = blob[4:4 + wlen]
#       nonce = blob[4 + wlen:4 + wlen + _AES_NONCE_SIZE]
#       ciphertext = blob[4 + wlen + _AES_NONCE_SIZE:]
#       aad = ref[:-4].encode()  # strip ".enc" -> file_id
#   else:
#       # legacy blob: infer wrapped size from current key (backward compat only)
#       wlen = self._private_key.key_size // 8
#       wrapped_key = blob[:wlen]
#       nonce = blob[wlen:wlen + _AES_NONCE_SIZE]
#       ciphertext = blob[wlen + _AES_NONCE_SIZE:]
#       aad = None
#   aesgcm.decrypt(nonce, ciphertext, aad)
# delete(): same _checked_blob_path gate.
```


- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest tests/test_document_vault.py tests/test_security/test_vault_security.py -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/adapters/document_storage/encrypted/pki_store.py tests/test_document_vault.py
git commit -m "fix(vault): validate refs, version blobs, bind file-id as AAD"
```

---

### Task 4: Public-key-only encrypt (put while locked)

**Files:**
- Modify: `app/adapters/document_storage/encrypted/pki_store.py:238-268`
- Modify: `app/modules/document_vault/local/__init__.py:20-23` (no logic change, docstring only if needed)
- Test: `tests/test_document_vault.py` (append)

**Interfaces:**
- Consumes: `self._public_key` loaded at init (Tasks 2-3).
- Produces: `put()` succeeds while locked using `self._public_key`; `get()/delete()/unlock_remaining()` still require unlocked. New error message for decrypt-path: `"Vault is locked (decrypt requires unlock)"`.

- [ ] **Step 1: Write the failing test**

```python
def test_pki_put_while_locked_succeeds_get_requires_unlock(tmp_path, monkeypatch) -> None:
    monkeypatch.chdir(tmp_path)
    from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
    store = PKIEncryptedStore(cert_dir=str(tmp_path / "c"), storage_dir=str(tmp_path / "d"))
    key = store.generated_master_key
    assert store.is_unlocked() is False
    ref = store.put(b"encrypt while locked")
    assert ref.endswith(".enc")
    import pytest
    with pytest.raises(PermissionError, match="locked"):
        store.get(ref)
    assert store.unlock(key) is True
    assert store.get(ref) == b"encrypt while locked"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_document_vault.py::test_pki_put_while_locked_succeeds_get_requires_unlock -v`
Expected: FAIL with `VaultLockedError: Vault is locked` on `put`.

- [ ] **Step 3: Write minimal implementation**

```python
# Replace _require_unlocked + put head:
def _require_unlocked_for_decrypt(self) -> None:
    if not self.is_unlocked():
        raise VaultLockedError("Vault is locked (decrypt requires unlock)")

def put(self, content: bytes) -> str:
    if self._public_key is None:
        raise VaultLockedError("Vault public key unavailable")
    file_id = str(uuid.uuid4())
    ref = f"{file_id}.enc"
    aes_key = AESGCM.generate_key(bit_length=_AES_KEY_SIZE_BITS)
    nonce = os.urandom(_AES_NONCE_SIZE)
    aesgcm = AESGCM(aes_key)
    ciphertext = aesgcm.encrypt(nonce, content, file_id.encode())  # AAD from Task 3
    wrapped_key = self._public_key.encrypt(
        aes_key,
        padding.OAEP(mgf=padding.MGF1(algorithm=hashes.SHA256()), algorithm=hashes.SHA256(), label=None),
    )
    (self._storage_dir / ref).write_bytes(
        b"V1" + len(wrapped_key).to_bytes(2, "big") + wrapped_key + nonce + ciphertext
    )
    return ref

def get(self, ref: str): self._require_unlocked_for_decrypt(); ...
def delete(self, ref: str): self._require_unlocked_for_decrypt(); ...
# Keep old _require_unlocked as alias to avoid breaking imports:
_require_unlocked = _require_unlocked_for_decrypt
```

- [ ] **Step 4: Run tests to verify them**

Run: `pytest tests/test_document_vault.py tests/test_security/test_vault_security.py tests/test_persistence_documents.py -v`
Expected: FAIL on the two existing locked-store tests, which assert `put` raises while locked. Update BOTH:

```python
# tests/test_document_vault.py::test_pki_put_get_while_locked_raises — change to:
ref = store.put(b"test")  # allowed while locked (public-key encrypt)
with pytest.raises(PermissionError, match="locked"):
    store.get(ref)
with pytest.raises(PermissionError, match="locked"):
    store.delete(ref)
store.unlock(store.generated_master_key)
assert store.get(ref) == b"test"

# tests/test_security/test_vault_security.py::test_pki_locked_store_raises_on_operations — change to:
store = PKIEncryptedStore(
    cert_dir=str(tmp_path / "certs"), storage_dir=str(tmp_path / "docs"),
)
ref = store.put(b"test")  # encrypt allowed while locked
with pytest.raises(PermissionError, match="locked"):
    store.get(ref)
with pytest.raises(PermissionError, match="locked"):
    store.delete(ref)
```

Then re-run the same command; Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/adapters/document_storage/encrypted/pki_store.py app/modules/document_vault/local/__init__.py tests/test_document_vault.py
git commit -m "feat(vault): allow encrypt-while-locked via public key"
```

---

### Task 5: Wire PKI as env-selectable default + fix local dev-store semantics

**Files:**
- Modify: `app/main.py:16,31`
- Modify: `app/core/config.py:42-43`
- Modify: `app/adapters/document_storage/local/__init__.py:11-41`
- Test: `tests/test_document_vault.py`, `tests/test_persistence_documents.py` (append/update)

**Interfaces:**
- Consumes: `BLITTO_DOCUMENT_STORE: str = "local"` (`"local"` | `"pki"`) and `BLITTO_VAULT_CERT_DIR`, `BLITTO_VAULT_STORAGE_DIR`, `BLITTO_VAULT_TTL_HOURS`.
- Produces: `build_document_vault() -> LocalDocumentVaultModule` used by `lifespan`; `LocalDocumentStore` explicitly plaintext-dev with honest `unlock_remaining() == 0.0`.

- [ ] **Step 1: Write the failing tests**

```python
def test_local_store_unlock_remaining_is_zero_when_unlocked() -> None:
    from app.adapters.document_storage.local import LocalDocumentStore
    store = LocalDocumentStore()
    store.unlock("anything")
    assert store.is_unlocked() is True
    assert store.unlock_remaining() == 0.0


def test_build_vault_selects_pki_when_env_set(tmp_path, monkeypatch) -> None:
    monkeypatch.chdir(tmp_path)
    # `settings` is a cached singleton built at import; patch the object itself.
    from app.core.config import settings
    monkeypatch.setattr(settings, "document_store", "pki")
    monkeypatch.setattr(settings, "vault_cert_dir", str(tmp_path / "certs"))
    monkeypatch.setattr(settings, "vault_storage_dir", str(tmp_path / "docs"))
    from app.main import build_document_vault
    vault = build_document_vault()
    from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
    assert isinstance(vault._store, PKIEncryptedStore)
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `pytest tests/test_document_vault.py::test_local_store_unlock_remaining_is_zero_when_unlocked tests/test_document_vault.py::test_build_vault_selects_pki_when_env_set -v`
Expected: FAIL — `unlock_remaining()` returns `86400.0`, `build_document_vault` does not exist.

- [ ] **Step 3: Write minimal implementation**

```python
# app/core/config.py — add:
document_store: str = "local"          # "local" | "pki"
vault_cert_dir: str = "certs"
vault_storage_dir: str = "encrypted_docs"
vault_ttl_hours: int = 12

# app/adapters/document_storage/local/__init__.py:
"""... plaintext dev-only adapter. Never use for real documents. ..."""
class LocalDocumentStore:
    # ... put/get/delete unchanged ...
    def unlock(self, master_key: str) -> bool:
        self._locked = False
        return True  # dev bypass: any key unlocks; NOT a security boundary
    def unlock_remaining(self) -> float:
        return 0.0  # no expiry concept for plaintext dev store

# app/main.py:
from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
from app.adapters.document_storage.local import LocalDocumentStore

def build_document_vault() -> LocalDocumentVaultModule:
    choice = settings.document_store.lower()
    if choice == "pki":
        store: DocumentStoreAdapter = PKIEncryptedStore(
            cert_dir=settings.vault_cert_dir,
            storage_dir=settings.vault_storage_dir,
            ttl_hours=settings.vault_ttl_hours,
        )
    elif choice == "local":
        if settings.environment.lower() != "local":
            raise RuntimeError(
                "BLITTO_DOCUMENT_STORE=local is dev-only; set 'pki' in production"
            )
        store = LocalDocumentStore()
    else:
        raise ValueError(f"Unknown BLITTO_DOCUMENT_STORE: {settings.document_store!r}")
    return LocalDocumentVaultModule(store=store)

# lifespan: app.state.document_vault = build_document_vault()
```

Reasoning: default stays `local` so the existing API test suite (which expects an already-unlocked vault) keeps working, but any non-local `environment` fails closed at startup unless `pki` is selected.

- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest tests/test_document_vault.py tests/test_persistence_documents.py tests/test_security/test_vault_security.py -q`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/main.py app/core/config.py app/adapters/document_storage/local/__init__.py tests/test_document_vault.py
git commit -m "feat(vault): env-selectable PKI default, honest local dev store"
```

---

### Task 6: Unlock rate-limit + honest lock + docs

**Files:**
- Modify: `app/api/routes.py:135-145`
- Modify: `app/adapters/document_storage/encrypted/pki_store.py:223-225` (docstring/comment only)
- Modify: `Readme.md:84-96`
- Test: `tests/test_security/test_vault_security.py` (append)

**Interfaces:**
- Consumes: `POST /api/vault/unlock {master_key}` + `vault.unlock()` from Task 2.
- Produces: HTTP 429 after 5 failed unlocks / 10 min per client IP; `lock()` docstring no longer claims memory zeroization; Readme documents `BLITTO_DOCUMENT_STORE=pki`, `BLITTO_VAULT_MASTER_KEY` via secret manager, 10 MB upload cap, encrypt-while-locked.

- [ ] **Step 1: Write the failing test**

```python
def test_vault_unlock_rate_limited_after_5_failures(client, tmp_path, monkeypatch) -> None:
    from tests.conftest import auth_headers, MD_EMAIL, MD_PW
    from app.domain.common import Role
    from app.modules.authorization.models import RegisterRequest
    from app.modules.document_vault.local import LocalDocumentVaultModule
    from app.adapters.document_storage.encrypted.pki_store import PKIEncryptedStore
    from app.api import routes as routes_mod

    monkeypatch.chdir(tmp_path)
    routes_mod._UNLOCK_ATTEMPTS.clear()  # module-global; isolate from other tests
    # The default local store accepts any key, so swap in a real PKI vault.
    client.app.state.document_vault = LocalDocumentVaultModule(
        store=PKIEncryptedStore(
            cert_dir=str(tmp_path / "certs"), storage_dir=str(tmp_path / "docs")
        )
    )
    auth = client.app.state.authorization
    if not any(u.email == MD_EMAIL for u in auth._users.values()):
        auth.register(RegisterRequest(email=MD_EMAIL, role=Role.MD, password=MD_PW))
    resp = client.post("/api/auth/login", json={"email": MD_EMAIL, "password": MD_PW})
    headers = auth_headers(resp.json()["access_token"])
    for _ in range(5):
        r = client.post("/api/vault/unlock", json={"master_key": "wrong"}, headers=headers)
        assert r.status_code == 401
    r = client.post("/api/vault/unlock", json={"master_key": "wrong"}, headers=headers)
    assert r.status_code == 429
```

Note: the limiter key is `request.client.host` (constant `"testclient"`), so the `_UNLOCK_ATTEMPTS.clear()` at test start is required to avoid cross-test state leak. Because other vault tests perform successful unlocks, also clear in Task 6's implementation via `_UNLOCK_ATTEMPTS.pop(ip, None)` on success (already specified).

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_security/test_vault_security.py::test_vault_unlock_rate_limited_after_5_failures -v`
Expected: FAIL — 6th attempt returns 401, not 429.

- [ ] **Step 3: Write minimal implementation**

```python
# app/api/routes.py top:
import time
_UNLOCK_ATTEMPTS: dict[str, list[float]] = {}
_WINDOW_S = 600.0
_MAX_FAILS = 5

def _unlock_allowed(ip: str) -> bool:
    now = time.monotonic()
    hits = [t for t in _UNLOCK_ATTEMPTS.get(ip, []) if now - t < _WINDOW_S]
    _UNLOCK_ATTEMPTS[ip] = hits
    return len(hits) < _MAX_FAILS

def _record_unlock_fail(ip: str) -> None:
    _UNLOCK_ATTEMPTS.setdefault(ip, []).append(time.monotonic())

# vault_unlock():
#   ip = request.client.host if request.client else "unknown"
#   if not _unlock_allowed(ip): raise HTTPException(429, "Too many unlock attempts")
#   ok = vault.unlock(body.get("master_key",""))
#   if not ok:
#       _record_unlock_fail(ip)
#       raise HTTPException(401, "Invalid master key")
#   _UNLOCK_ATTEMPTS.pop(ip, None)
#   return {"status": "unlocked"}

# pki_store.py lock():
def lock(self) -> None:
    """Lock vault: drop in-memory private key reference (GC reclaims; not secure zeroization)."""
    self._private_key = None
    self._unlocked_until = None
```

Also fix the module docstring at `pki_store.py:3-7` (finding #7 lives here, not in `lock()`):

```python
"""Document storage adapter - PKI envelope encryption with RSA-4096 + AES-256-GCM.

Vault lifecycle:
  First run → generates RSA key pair + random 256-bit master key.
  Admin enters master key → decrypts RSA private key → 12-hour unlock window.
  Lock / expiry → private key reference dropped (reclaimed by GC; NOT secure zeroization).
"""
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `pytest tests/test_security/test_vault_security.py tests/test_document_vault.py -q && pytest --cov=app --cov-report=term-missing -q`
Expected: PASS, coverage >= 80%.

- [ ] **Step 5: Commit + docs + graph**

```bash
git add app/api/routes.py app/adapters/document_storage/encrypted/pki_store.py Readme.md tests/test_security/test_vault_security.py
git commit -m "fix(vault): rate-limit unlock, honest lock semantics + docs"
python scripts/run_graphify.py
```

Readme replacement for lines 84-96:

```markdown
## Document Security (current state)
- **Store selectable via `BLITTO_DOCUMENT_STORE`**: `local` (plaintext, dev-only — startup fails if `environment != local`) or `pki` (RSA-4096 + AES-256-GCM envelope). Production must set `pki`.
- **Master key never persisted by the app**: first run emits the base64 key once to the log; store it in your secret manager as `BLITTO_VAULT_MASTER_KEY`, then call `POST /api/vault/unlock`.
- **Vault gate**: all document endpoints still require an unlocked vault (423 when locked). The PKI store additionally supports encrypt-with-public-key, so uploads can be decoupled from unlock later; the API does not expose that yet (see `test_vault_blocks_upload_when_locked`).
- **Uploads**: 10 MB cap, extension allow-list, 423 when locked, 429 after 5 failed unlocks per IP / 10 min.
```

---

## Self-Review

- **Plan-review round (2026-09-12):** a reviewer subagent flagged 6 issues; all fixed inline:
  1. Task 4 now updates the second locked-store test (`tests/test_security/test_vault_security.py::test_pki_locked_store_raises_on_operations`).
  2. Task 5 test patches the cached `settings` singleton instead of `monkeypatch.setenv` (which had no effect).
  3. Task 6 rate-limit test swaps in a real `PKIEncryptedStore` vault and clears module-global limiter state.
  4. Task 3 AAD test now copies the whole blob to the other ref, so it genuinely exercises AAD (not key mismatch).
  5. Finding #7's false "zeroed from memory" claim is fixed in the **module docstring** (`pki_store.py:3-7`), not just `lock()`.
  6. Readme no longer claims API uploads work while locked; it states the 423 gate remains and public-key encrypt is not yet exposed.
  7. Finding #9 fully addressed: V1 blob encodes an explicit 2-byte wrapped-key length (no more `key_size//8` inference for new blobs; legacy fallback retained).
- Spec coverage: all 12 findings mapped — Task 1 (.env), Task 2 (missing pub / key-length / settings leak), Task 3 (traversal / version + explicit length / AAD), Task 4 (private-key-for-encrypt via cached pubkey), Task 5 (plaintext default fail-closed + fake 86400), Task 6 (PBKDF2 DoS rate-limit / zeroize claim / docs).
- Placeholder scan: no TBD/TODO/appropriate-handling; every step has runnable test code, exact `pytest` command, concrete implementation snippet, exact `git add` paths.
- Type consistency: `build_document_vault() -> LocalDocumentVaultModule`, `vault._store: DocumentStoreAdapter`, `unlock(str)->bool`, `put(bytes)->str`, `get(str)->Optional[bytes]`, `ref: {file_id}.enc`, V1 blob `b"V1"+len(2B)+wrapped+nonce+ct` used identically in Tasks 3-4.
- Accepted scope limit: whole-file `read_bytes/write_bytes` remains; 10 MB route cap bounds memory. Chunked streaming deferred (YAGNI for this skeleton).
