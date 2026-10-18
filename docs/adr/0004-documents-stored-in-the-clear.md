# ADR 0004: Documents are stored in the clear

## Status
Accepted — encryption deferred

## Context
The migration design (§7) and the old Readme promised an AES-256 envelope for
document bytes, and issue #71 specified a master-key unlock plus an RSA-4096 +
AES-256-GCM store ported from `PKIEncryptedStore` (with a legacy-format read
path for the parallel-run window). While planning L4 the maintainer removed
encryption from this version: documents are stored as plaintext bytes, and the
vault unlock gate (`/vault/status|unlock|lock`, `423` when locked) goes with it.
Documents remain role-gated (staff) plus the `GRANTED`/ownership rules for
inventors.

## Decision
`App\Domain\Vault\DocumentStore` is a published port with a single adapter,
`LocalDocumentStore`, writing plaintext bytes to the private `vault` disk
(`storage/app/vault`). `content_ref` is a bare UUID (`^[0-9a-f-]{36}$`), never
client input, so no caller-supplied string can address a path. Upload rules
(extension allow-list, 10 MiB cap, filename length) live in
`App\Domain\Vault\DocumentRules`; deletion is refused once the application is
filed (CONTEXT.md invariant 3). `BLITTODB_DOCUMENT_STORE` stays as the adapter
seam and accepts only `local`; `pki` fails loudly at resolution.

## Consequences
- Document confidentiality rests on the filesystem and the role gates, not on
  cryptography: the disk holds readable patent documents.
- No `BLITTODB_VAULT_MASTER_KEY`, no RSA key pair, no key rotation, no unlock
  TTL, no `423` responses. `AttemptThrottle::VAULT_UNLOCK` survives unused.
- The old Readme's AES-256 claim is not true of this application until an
  encrypted adapter exists.
- Adding encryption later is a second `DocumentStore` adapter plus a migration
  of existing blobs; no controller or domain flow changes.
- On-disk compatibility with blobs made by the Python store is waived (issue
  #71: there is no production data), so no legacy read path is implemented.
