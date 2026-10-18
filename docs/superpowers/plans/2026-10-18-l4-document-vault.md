# L4 — Document Vault Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Laravel backend document storage — staff upload/list/download/delete on a pluggable `DocumentStore` seam, the inventory blessed-`GRANTED` download (single file or ZIP) with `request-document`, and the missing-documents worklist — with every store/delete/download/request audited.

**Architecture:** Thin controllers validate input and delegate to deep `App\Domain\Vault\*` services (`DocumentService` for storage rules, `GrantedPatentService` for the inventor-facing download and request flow). Bytes go through a `DocumentStore` port whose only production adapter is `LocalDocumentStore` (plaintext on a private `vault` disk); encryption is deferred and arrives later as a second adapter behind the same port. Controllers stay thin; models stay persistence-only.

**Tech Stack:** Laravel 13.35, PHP 8.3.6, SQLite + Eloquent, Pest 4, Pint, `ext-zip` (present), Laravel `Storage` fakery.

**Spec:** `docs/superpowers/specs/2026-10-09-blitto-patent-database-laravel-migration-design.md` (§5 `documents` table, §7 domain behavior). Tracking issue: [#71](https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/71). Context: `BIGGER_PICTURE.md` §6/§9, `CONTEXT.md` invariant 3 (document immutability) and invariant 5 (audit trail), invariant 6 (confidentiality). Behavioral reference (never a gate — "journeys are the reference, not a porting checklist"): git tag `pre-laravel-final-state` — `app/api/routes.py` (document routes), `app/modules/document_vault/`, `app/adapters/document_storage/`, `tests/test_document_routes.py`, `tests/test_granted_download_api.py`, `tests/test_doc_scope.py`, `tests/test_security/test_file_upload_security.py`, `tests/test_security/test_auth_upload.py`, `tests/journeys/test_e2e_flows.py`.

## Scope change (2026-10-18, agreed with the human partner)

Issue #71 asks for master-key unlock, an RSA-4096 + AES-256-GCM encrypted store, and download rules. The human partner removed encryption and the unlock gate from this version:

- **No encryption.** Documents are stored as plaintext bytes on a private disk. Issue #71 deliverables 1 and 2 and spec §6 (`BLITTODB_VAULT_MASTER_KEY` unlock, 12 h TTL) and §7 (encrypted store, RSA/AES envelope) are **not** implemented here. Accepted and recorded by ADR-0004 in Task 9.
- **No vault gate.** No `/vault/status`, `/vault/unlock`, `/vault/lock`; nothing returns `423`. Access is role-based plus the `GRANTED`/ownership rules. `AttemptThrottle` and its `/_test/vault-unlock` probe stay in the tree untouched (backend/AGENTS.md forbids removing tests without approval); they keep proving the generic throttle + RBAC gate.
- **The encryption decision is deferred, not dropped.** The `DocumentStore` port exists precisely so an `EncryptedDocumentStore` can be added later without touching the flows, and `BLITTODB_DOCUMENT_STORE=pki` fails loudly with "not implemented" rather than silently storing plaintext.

**Rulings baked into this plan** (deliberate divergences from the reference, justified by the scope change, CONTEXT.md, or the L3 conventions, which are the binding authority):

- **Immutability blocks delete, not upload.** CONTEXT.md invariant 3 freezes documents at filing time, but the granted-patent flow *requires* uploading a document after the application is `GRANTED`. So `DocumentService::delete` calls `DocumentImmutability::assertModifiable($document)` (409 after filing) while upload stays open to staff at any status. `DocumentIsImmutable` is mapped to `409` (this mapping is new; the exception existed since L1 without one).
- **Upload transport is `multipart/form-data`** with a `file` field, not the reference's raw body + `?filename=` query. The L6 Blade/Livewire UI cannot easily post a raw body, and Laravel validation is built around `UploadedFile`.
- **`content_ref` is a bare UUID** (`^[0-9a-f-]{36}$`), never client input, so path traversal is structurally impossible. The store returns `null`/`false` for unknown or malformed refs instead of throwing.
- **Error bodies follow L3** (`{"message": ...}`); the pending-document conflict is `409` with `{"message": ..., "code": "document_pending"}` — the reference's `{"detail": {...}}` object is **not** ported.
- **`413` stays `413`** for oversized uploads (the reference's code) rather than Laravel's default `422`. Extension, filename-length and empty-file violations are `422`.
- **Filename length is capped at 255** (the `documents.filename` column width); the reference accepted a 500-character name that would have failed on MySQL.
- **Config cleanup:** `vault_master_key`, `vault_cert_dir`, `vault_ttl_hours` and `vault_storage_dir` are removed from `config/blitto.php` and `.env.example` — all four only ever existed for the dropped crypto/gate. The storage location now lives once, in `config/filesystems.php`, as the `vault` disk (`storage/app/vault`). `document_store` stays as the adapter seam.
- **Duplicate `request-document` detection is audit-based** (`audit_logs` has `user_id`, `application_id`, `action`), matching the reference's "one open request per user" rule: a second request by the same user is `429` forever, even after the document arrives.
- **Staff per-document downloads are not audited** (parity — a download is not a state change); the inventor `GET /applications/{id}/download` is audited (`download_granted_patent`) because the reference did.
- **`X-Downloaded-At` is set only on the inventor download** (parity).

## Global Constraints

- Run all commands from `backend/`. Test runner: `php artisan test --compact <args>` (or `vendor/bin/pest`).
- TDD always: RED → GREEN, one vertical slice at a time, never refactor while RED (AGENTS.md).
- Tests assert behavior through public interfaces (HTTP routes, domain service public methods) — not private internals.
- Domain logic in `app/Domain/*`; controllers thin; **no business logic in controllers or Eloquent models**; models are persistence-only.
- Env prefix `BLITTODB_`; session (`web` guard) auth only — no bearer tokens.
- Roles are the lowercase enum values `user` / `director` / `md` (`App\Domain\Authorization\Role`); staff gates are `role:director,md`.
- Status codes are part of the contract: guest `401`, wrong role `403`, validation/rule violations `422`, unknown application / unknown document `404`, oversize upload `413`, immutability and pending-document conflicts `409`, duplicate document request `429`.
- Error bodies use the L2 convention `{"message": "..."}`; validation errors use Laravel's `{"message": ..., "errors": {...}}`.
- Conventional commits, prefix `feat(l4):` / `test(l4):` / `docs(l4):` / `chore(l4):`.
- After code changes: `vendor/bin/pint --dirty --format agent`; at the end `graphify update .` from the repository root.
- PHP style (Boost `php` rules): constructor property promotion, explicit parameter + return types, curly braces always, PHPDoc array shapes, TitleCase enum keys.
- Laravel version APIs are frozen at 13.35 — do not assume other versions. **No new Composer dependencies** (this is why there is no `phpseclib`, `intervention/image` or PDF package).
- Create test files with `php artisan make:test --pest <Name>` (project convention); the paths given in each task are where the command should land.
- There is **no `Application` factory** and none is added: tests build domain rows with `Application::query()->create([...])` and users with `User::factory()->{user,director,md}()`.
- **Upload size note:** this box ships `upload_max_filesize=2M` / `post_max_size=8M`, so real uploads above that never reach PHP. Tests are unaffected (`UploadedFile::fake()` bypasses PHP's limits). Task 9 documents `post_max_size=12M` / `upload_max_filesize=12M` and the CI workflow gets matching `ini-values`.
- **No schema change:** `documents` (`id`, `application_id`, `filename`, `file_size`, `content_ref`, `uploaded_by`, `uploaded_at`) and `notifications` (email-addressed, no user FK) already fit.

## Review Focus

The input classes and failure modes most likely to bite a person using this software; the task named in parentheses owns the test that pins each one.

1. **Oversized uploads that never reach the app.** A 4 MB PDF is silently truncated/rejected by PHP's `upload_max_filesize=2M` before Laravel runs, yielding a confusing `422` instead of `413`/success (Task 4 test pins the app-level `413` and `10 MiB` boundary; Task 9 pins the documented ini requirement).
2. **Upload application-resolution ordering.** A request with no file → `422`; a valid file for an unknown application → `404`; a valid file for a real application with a disallowed extension → `422`; the same file over the cap → `413`. Extension comes from the last dot, case-insensitively, after the application is resolved (Task 4).
3. **Path traversal and header injection.** `content_ref` values are server-generated and validated against `^[0-9a-f-]{36}$`; a stored client filename like `../../../evil.pdf` must produce a `Content-Disposition` with neither `..` nor a `/` and exactly two quote characters (Tasks 1, 5).
4. **The immutability boundary.** Deleting a document on a filed application is `409`; uploading a new document to a `GRANTED` application still works (the granted patent itself arrives that way); a brand-new document is deletable (Tasks 3, 5).
5. **Granted-download authorization and pending conflict.** A `user` downloading someone else's patent gets `403`; a non-`GRANTED` patent gives `403` even for staff; a `GRANTED` patent with no documents gives `409` with `code=document_pending`; a ZIP download omits entries whose bytes are gone but still returns `200` (Task 6).
6. **Notification fan-out and duplicate requests.** The first upload on a `GRANTED` application notifies every distinct inventor exactly once; a second upload does not re-notify; `request-document` notifies the admin and a second call by the same user is `429`; a refused request writes neither notification nor audit row (Tasks 2, 7).

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Domain/Vault/DocumentStore.php` | Port: `put`/`get`/`delete` on opaque refs |
| `app/Domain/Vault/LocalDocumentStore.php` | Plaintext adapter over the `vault` disk |
| `app/Domain/Vault/UnsupportedDocumentStore.php` | Thrown when `document_store` is not `local` |
| `app/Domain/Vault/DocumentRules.php` | Extension allow-list, size cap, filename cap (all constants) |
| `app/Domain/Vault/DocumentFilename.php` | Safe download names + content-type mapping |
| `app/Domain/Vault/DocumentService.php` | Store/list/exists/delete + audit + first-document notification |
| `app/Domain/Vault/GrantedPatentService.php` | Inventor download assembly + request-document flow |
| `app/Domain/Vault/{UnsupportedFileType,DocumentTooLarge,InvalidDocumentFilename,DocumentNotFound,DocumentIsImmutable*,DocumentPending,DocumentContentMissing,PatentNotGranted,NotYourPatent,DocumentRequestRefused,DocumentAlreadyRequested}.php` | Small domain exceptions |

`*` already exists from L1; this plan adds its HTTP mapping and its first caller.

| `app/Http/Controllers/DocumentController.php` | Staff upload/list/download/delete/missing-documents |
| `app/Http/Controllers/GrantedPatentController.php` | Inventor download + request-document |
| `config/filesystems.php` | New `vault` disk |
| `config/blitto.php`, `.env.example` | Drop the dead crypto/gate config |
| `app/Providers/AppServiceProvider.php` | Bind `DocumentStore` from config |
| `bootstrap/app.php` | Map the new domain exceptions to HTTP |

---

### Task 1: Document storage seam (`DocumentStore`, `LocalDocumentStore`, `vault` disk)

**Files:**
- Create: `app/Domain/Vault/DocumentStore.php`
- Create: `app/Domain/Vault/LocalDocumentStore.php`
- Create: `app/Domain/Vault/UnsupportedDocumentStore.php`
- Modify: `config/filesystems.php` (add a `vault` disk)
- Modify: `config/blitto.php:36-41` (drop `vault_master_key`, `vault_cert_dir`, `vault_storage_dir`, `vault_ttl_hours`; keep `document_store`)
- Modify: `.env.example:76-80`
- Modify: `app/Providers/AppServiceProvider.php` (bind the port)
- Test: `tests/Feature/DocumentStoreTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: `App\Domain\Vault\DocumentStore` with `put(string $contents): string`, `get(string $ref): ?string`, `delete(string $ref): bool`; `LocalDocumentStore::__construct(private readonly Filesystem $disk)`; the `vault` disk; `UnsupportedDocumentStore extends RuntimeException`.

- [ ] **Step 1: Add the `vault` disk**

In `config/filesystems.php`, inside `'disks' => [`, after the `'local'` entry:

```php
        // Plaintext document bytes for the vault. Never served by the web
        // server; every read goes through App\Domain\Vault\DocumentStore.
        'vault' => [
            'driver' => 'local',
            'root' => storage_path('app/vault'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],
```

- [ ] **Step 2: Remove the dead crypto/gate config**

In `config/blitto.php`, replace the four vault lines and their comment with:

```php
    // Document storage adapter: "local" (plaintext, this version).
    // "pki" (encrypted) is not implemented — see docs/adr/0004.
    'document_store' => env('BLITTODB_DOCUMENT_STORE', 'local'),
```

In `.env.example`, replace lines 76-80 with:

```
# BLITTODB_DOCUMENT_STORE=local
```

- [ ] **Step 3: Write the failing store test**

Create `tests/Feature/DocumentStoreTest.php`:

```php
<?php

use App\Domain\Vault\DocumentStore;
use App\Domain\Vault\UnsupportedDocumentStore;
use Illuminate\Support\Facades\Storage;

it('stores, retrieves and deletes document bytes', function () {
    Storage::fake('vault');

    $store = app(DocumentStore::class);
    $ref = $store->put('patent bytes');

    expect($ref)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($store->get($ref))->toBe('patent bytes')
        ->and($store->get('11111111-1111-1111-1111-111111111111'))->toBeNull()
        ->and($store->delete($ref))->toBeTrue()
        ->and($store->get($ref))->toBeNull()
        ->and($store->delete($ref))->toBeFalse();
});

it('refuses refs that are not opaque identifiers', function () {
    Storage::fake('vault');

    $store = app(DocumentStore::class);

    expect($store->get('../private_key.enc'))->toBeNull()
        ->and($store->get('../../etc/passwd'))->toBeNull()
        ->and($store->get('not-a-uuid'))->toBeNull()
        ->and($store->delete('../escape'))->toBeFalse();
});

it('fails loudly when the encrypted store is requested', function () {
    config(['blitto.document_store' => 'pki']);
    $this->app->forgetInstance(DocumentStore::class);

    expect(fn () => app(DocumentStore::class))->toThrow(UnsupportedDocumentStore::class);
});
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/DocumentStoreTest.php`
Expected: FAIL — `Target class [App\Domain\Vault\DocumentStore] does not exist` (and the config test from the next step has not been touched yet).

- [ ] **Step 5: Write the port, the adapter and the binding**

`app/Domain/Vault/DocumentStore.php`:

```php
<?php

namespace App\Domain\Vault;

/**
 * Document byte storage port. Implementations deal in opaque refs only;
 * callers never see a filesystem path. Encryption, if it ever returns,
 * is a second adapter behind this interface (docs/adr/0004).
 */
interface DocumentStore
{
    /**
     * Store bytes and return the opaque ref that retrieves them.
     */
    public function put(string $contents): string;

    /**
     * Return the bytes for a ref, or null when the ref is unknown or malformed.
     */
    public function get(string $ref): ?string;

    /**
     * Delete a ref's bytes. False when the ref is unknown or malformed.
     */
    public function delete(string $ref): bool;
}
```

`app/Domain/Vault/UnsupportedDocumentStore.php`:

```php
<?php

namespace App\Domain\Vault;

use RuntimeException;

/**
 * Raised when BLITTODB_DOCUMENT_STORE names an adapter that does not exist.
 */
final class UnsupportedDocumentStore extends RuntimeException {}
```

`app/Domain/Vault/LocalDocumentStore.php`:

```php
<?php

namespace App\Domain\Vault;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Plaintext adapter over the private `vault` disk. Refs are bare UUIDs so
 * no caller-supplied string can ever address a path (traversal-proof).
 */
final class LocalDocumentStore implements DocumentStore
{
    /** Refs the store will address: a bare lowercase UUID. */
    private const string REF_PATTERN = '/^[0-9a-f-]{36}$/';

    public function __construct(private readonly Filesystem $disk) {}

    public function put(string $contents): string
    {
        $ref = (string) Str::uuid();
        $this->disk->put($ref, $contents);

        return $ref;
    }

    public function get(string $ref): ?string
    {
        if (! $this->isAddressable($ref) || ! $this->disk->exists($ref)) {
            return null;
        }

        return $this->disk->get($ref);
    }

    public function delete(string $ref): bool
    {
        if (! $this->isAddressable($ref) || ! $this->disk->exists($ref)) {
            return false;
        }

        return $this->disk->delete($ref);
    }

    private function isAddressable(string $ref): bool
    {
        return preg_match(self::REF_PATTERN, $ref) === 1;
    }
}
```

In `app/Providers/AppServiceProvider.php`, add `register()`:

```php
    public function register(): void
    {
        $this->app->singleton(DocumentStore::class, static function (): DocumentStore {
            $configured = (string) config('blitto.document_store');

            return match ($configured) {
                'local' => new LocalDocumentStore(Storage::disk('vault')),
                default => throw new UnsupportedDocumentStore(
                    "Document store [{$configured}] is not available; only \"local\" is implemented.",
                ),
            };
        });
    }
```

with imports `App\Domain\Vault\DocumentStore`, `App\Domain\Vault\LocalDocumentStore`, `App\Domain\Vault\UnsupportedDocumentStore`, `Illuminate\Support\Facades\Storage`. Keep the existing `boot()` untouched.

- [ ] **Step 6: Run the store test to verify it passes**

Run: `php artisan test --compact tests/Feature/DocumentStoreTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Update the config test for the removed keys**

`tests/Feature/BlittoConfigTest.php` currently asserts `vault_cert_dir`, `vault_storage_dir`, `vault_ttl_hours` and drives its env-prefix case through `BLITTODB_VAULT_TTL_HOURS`. Replace the first test's vault block with a single assertion and re-point the env case:

```php
        ->and(config('blitto.document_store'))->toBe('local')
```

```php
it('reads settings from BLITTODB_-prefixed env vars', function () {
    putenv('BLITTODB_NIPO_FOLLOW_UP_INTERVAL_HOURS=6');
    $_ENV['BLITTODB_NIPO_FOLLOW_UP_INTERVAL_HOURS'] = '6';

    $this->refreshApplication();

    expect(config('blitto.nipo_follow_up_interval_hours'))->toBe(6);
});

afterEach(function () {
    putenv('BLITTODB_NIPO_FOLLOW_UP_INTERVAL_HOURS');
    unset($_ENV['BLITTODB_NIPO_FOLLOW_UP_INTERVAL_HOURS']);
});
```

- [ ] **Step 8: Run the config test**

Run: `php artisan test --compact tests/Feature/BlittoConfigTest.php tests/Feature/SchemaTest.php`
Expected: PASS. (`SchemaTest` guards the table list, which is unchanged.)

- [ ] **Step 9: Commit**

```bash
git add app/Domain/Vault/DocumentStore.php app/Domain/Vault/LocalDocumentStore.php app/Domain/Vault/UnsupportedDocumentStore.php app/Providers/AppServiceProvider.php config/filesystems.php config/blitto.php .env.example tests/Feature/DocumentStoreTest.php tests/Feature/BlittoConfigTest.php
git commit -m "feat(l4): add plaintext document store port and vault disk"
```

---

### Task 2: Document rules, naming and the storage service (`DocumentService`)

**Files:**
- Create: `app/Domain/Vault/DocumentRules.php`
- Create: `app/Domain/Vault/DocumentFilename.php`
- Create: `app/Domain/Vault/UnsupportedFileType.php`
- Create: `app/Domain/Vault/DocumentTooLarge.php`
- Create: `app/Domain/Vault/InvalidDocumentFilename.php`
- Create: `app/Domain/Vault/DocumentService.php`
- Modify: `bootstrap/app.php` (render the three new exceptions)
- Test: `tests/Feature/DocumentServiceTest.php`

**Interfaces:**
- Consumes: `DocumentStore` (Task 1), `AuditRecorder`, `NotificationService` (L3).
- Produces:
  - `DocumentRules::MAX_BYTES`, `DocumentRules::MAX_FILENAME_LENGTH`, `DocumentRules::ALLOWED_EXTENSIONS`, `DocumentRules::extensionOf(string $filename): string`, `DocumentRules::isAllowed(string $filename): bool`, `DocumentRules::acceptedList(): string` (e.g. `.csv, .doc, ...`).
  - `DocumentFilename::safe(string $filename): string`, `DocumentFilename::mimeType(string $filename): string`.
  - `DocumentService::store(Application $application, UploadedFile $file, User $actor, ?string $ipAddress = null): Document`, `DocumentService::listFor(Application $application): Collection<int, Document>`, `DocumentService::hasDocuments(Application $application): bool`.
  - Exceptions `UnsupportedFileType`, `DocumentTooLarge`, `InvalidDocumentFilename` mapped to `422`, `413`, `422`.

- [ ] **Step 1: Write the failing rules test**

Create `tests/Feature/DocumentServiceTest.php`:

```php
<?php

use App\Domain\Vault\DocumentRules;
use App\Domain\Vault\DocumentService;
use App\Domain\Vault\DocumentTooLarge;
use App\Domain\Vault\InvalidDocumentFilename;
use App\Domain\Vault\UnsupportedFileType;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function documentStaff(): User
{
    return User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
}

it('accepts the allow-listed extensions case-insensitively', function (string $filename) {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Allowed']);
    $service = app(DocumentService::class);

    $document = $service->store(
        $application,
        UploadedFile::fake()->createWithContent($filename, 'bytes'),
        documentStaff(),
    );

    expect($document->filename)->toBe($filename)
        ->and($document->file_size)->toBe(5)
        ->and($document->uploaded_by)->toBe('director@pdn.ac.lk')
        ->and($service->hasDocuments($application))->toBeTrue();
})->with(['specification.pdf', 'TEST.PDF', 'notes.docx', 'sheet.XLSX', 'photo.jpeg']);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/DocumentServiceTest.php`
Expected: FAIL — `Class "App\Domain\Vault\DocumentService" not found`.

- [ ] **Step 3: Write `DocumentRules` and `DocumentFilename`**

`app/Domain/Vault/DocumentRules.php`:

```php
<?php

namespace App\Domain\Vault;

/**
 * Upload rules — port of pre-laravel-final-state
 * `app/api/routes.py::_validate_extension` plus its 10 MiB cap.
 */
final class DocumentRules
{
    /** @var list<string> */
    public const array ALLOWED_EXTENSIONS = ['csv', 'doc', 'docx', 'jpeg', 'jpg', 'pdf', 'png', 'txt', 'xls', 'xlsx'];

    public const int MAX_BYTES = 10 * 1024 * 1024;

    /** The `documents.filename` column width. */
    public const int MAX_FILENAME_LENGTH = 255;

    /**
     * Lowercased extension after the last dot; '' when there is none.
     */
    public static function extensionOf(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return mb_strtolower($extension);
    }

    public static function isAllowed(string $filename): bool
    {
        return in_array(self::extensionOf($filename), self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * Human-readable allow-list for error messages, e.g. ".csv, .doc, ...".
     */
    public static function acceptedList(): string
    {
        $extensions = array_map(static fn (string $extension): string => ".{$extension}", self::ALLOWED_EXTENSIONS);

        return implode(', ', $extensions);
    }
}
```

`app/Domain/Vault/DocumentFilename.php`:

```php
<?php

namespace App\Domain\Vault;

/**
 * Filename concerns for delivery — port of pre-laravel-final-state
 * `app/api/routes.py::_safe_filename` and its extension→media-type map.
 */
final class DocumentFilename
{
    /** @var array<string, string> */
    private const array MIME_TYPES = [
        'csv' => 'text/csv',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'txt' => 'text/plain',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * A download-safe name: no directory components, no quote/CR/LF characters.
     */
    public static function safe(string $filename): string
    {
        $basename = substr($filename, (int) strrpos('/'.$filename, '/'));
        $basename = str_replace(['\\', '"', "\r", "\n"], '', $basename);
        $basename = trim($basename);

        return $basename === '' ? 'patent' : $basename;
    }

    public static function mimeType(string $filename): string
    {
        return self::MIME_TYPES[DocumentRules::extensionOf($filename)] ?? 'application/octet-stream';
    }
}
```

- [ ] **Step 4: Run the rules test to verify it passes**

Run: `php artisan test --compact tests/Feature/DocumentServiceTest.php`
Expected: FAIL with `Class "App\Domain\Vault\DocumentService" not found` — the rules compile but the service that calls them does not exist yet.

- [ ] **Step 5: Write `DocumentService::store`**

`app/Domain/Vault/DocumentService.php`:

```php
<?php

namespace App\Domain\Vault;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Document storage rules — port of pre-laravel-final-state
 * `app/modules/document_vault/local/__init__.py` plus the upload route's
 * extension/size gates. Bytes live behind the DocumentStore port.
 */
final class DocumentService
{
    public function __construct(
        private readonly DocumentStore $store,
        private readonly AuditRecorder $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @throws UnsupportedFileType
     * @throws DocumentTooLarge
     * @throws InvalidDocumentFilename
     */
    public function store(
        Application $application,
        UploadedFile $file,
        User $actor,
        ?string $ipAddress = null,
    ): Document {
        $filename = (string) $file->getClientOriginalName();

        $this->assertUploadable($filename, (int) $file->getSize());

        $document = Document::query()->create([
            'application_id' => $application->id,
            'filename' => $filename,
            'file_size' => (int) $file->getSize(),
            'content_ref' => $this->store->put((string) $file->get()),
            'uploaded_by' => (string) $actor->email,
        ]);

        $this->audit->record('store_document', actor: $actor, application: $application, ipAddress: $ipAddress);

        $this->announceFirstGrantedDocument($application, $document);

        return $document;
    }

    /**
     * @return Collection<int, Document>
     */
    public function listFor(Application $application): Collection
    {
        return Document::query()
            ->where('application_id', $application->id)
            ->orderBy('uploaded_at')
            ->orderBy('id')
            ->get();
    }

    public function hasDocuments(Application $application): bool
    {
        return Document::query()->where('application_id', $application->id)->exists();
    }

    private function assertUploadable(string $filename, int $size): void
    {
        if ($filename === '' || mb_strlen($filename) > DocumentRules::MAX_FILENAME_LENGTH) {
            throw new InvalidDocumentFilename(
                'Document filename must be between 1 and '.DocumentRules::MAX_FILENAME_LENGTH.' characters',
            );
        }

        if (! DocumentRules::isAllowed($filename)) {
            $extension = DocumentRules::extensionOf($filename);

            throw new UnsupportedFileType(
                "File type '.{$extension}' not allowed. Accepted: ".DocumentRules::acceptedList(),
            );
        }

        if ($size > DocumentRules::MAX_BYTES) {
            throw new DocumentTooLarge('Document too large (max 10 MB)');
        }
    }

    /**
     * A GRANTED application that receives its first document tells the
     * inventors the patent is downloadable (reference: `send_download_ready`).
     */
    private function announceFirstGrantedDocument(Application $application, Document $document): void
    {
        if (ApplicationStatus::from($application->status) !== ApplicationStatus::GRANTED) {
            return;
        }

        $isFirst = Document::query()
            ->where('application_id', $application->id)
            ->whereKeyNot($document->id)
            ->doesntExist();

        if (! $isFirst) {
            return;
        }

        $this->notifications->notifyInventors(
            $application,
            'Patent document ready',
            "The granted patent document for application {$application->id} is now available to download.",
            'download_ready',
        );
    }
}
```

`app/Domain/Vault/UnsupportedFileType.php` (add the other two in the same style):

```php
<?php

namespace App\Domain\Vault;

use RuntimeException;

/** The uploaded extension is outside the allow-list (HTTP 422). */
final class UnsupportedFileType extends RuntimeException {}
```

- [ ] **Step 6: Map the three exceptions in `bootstrap/app.php`**

Add alongside the existing renders:

```php
        $exceptions->render(
            fn (UnsupportedFileType $e) => response()->json(['message' => $e->getMessage()], 422),
        );

        $exceptions->render(
            fn (InvalidDocumentFilename $e) => response()->json(['message' => $e->getMessage()], 422),
        );

        $exceptions->render(
            fn (DocumentTooLarge $e) => response()->json(['message' => $e->getMessage()], 413),
        );
```

with the matching `use` statements.

- [ ] **Step 7: Run the service test to verify it passes**

Run: `php artisan test --compact tests/Feature/DocumentServiceTest.php`
Expected: PASS (5 dataset cases).

- [ ] **Step 8: Add the rejection and notification tests**

Append to `tests/Feature/DocumentServiceTest.php`:

```php
it('rejects disallowed, extensionless and over-long filenames', function (string $filename) {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Rejected']);

    expect(fn () => app(DocumentService::class)->store(
        $application,
        UploadedFile::fake()->createWithContent($filename, 'bytes'),
        documentStaff(),
    ))->toThrow(UnsupportedFileType::class);

    expect(Document::query()->count())->toBe(0);
})->with(['evil.exe', 'no-extension', 'archive.tar.gz', 'script.php']);

it('rejects a filename longer than the column width', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Long name']);

    expect(fn () => app(DocumentService::class)->store(
        $application,
        UploadedFile::fake()->createWithContent(str_repeat('a', 252).'.pdf', 'bytes'),
        documentStaff(),
    ))->toThrow(InvalidDocumentFilename::class);
});

it('accepts a file of exactly the size cap and rejects one byte more', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Sizes']);
    $service = app(DocumentService::class);

    $atCap = $service->store(
        $application,
        UploadedFile::fake()->create('at-cap.pdf', 10240, 'application/pdf'),
        documentStaff(),
    );

    expect($atCap->file_size)->toBe(10 * 1024 * 1024);

    expect(fn () => $service->store(
        $application,
        UploadedFile::fake()->create('over-cap.pdf', 10241, 'application/pdf'),
        documentStaff(),
    ))->toThrow(DocumentTooLarge::class);
});

it('notifies inventors once when a granted application receives its first document', function () {
    Storage::fake('vault');
    $application = Application::query()->create([
        'title' => 'Granted',
        'status' => 'GRANTED',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);
    $service = app(DocumentService::class);

    $service->store($application, UploadedFile::fake()->createWithContent('grant.pdf', 'a'), documentStaff());
    $service->store($application, UploadedFile::fake()->createWithContent('grant-2.pdf', 'b'), documentStaff());

    expect(Notification::query()->where('kind', 'download_ready')->count())->toBe(1)
        ->and(Notification::query()->sole()->recipient_email)->toBe('ada@pdn.ac.lk');
});

it('audits every stored document', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Audited']);

    app(DocumentService::class)->store(
        $application,
        UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
        documentStaff(),
        '10.0.0.9',
    );

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'store_document',
        'application_id' => $application->id,
        'ip_address' => '10.0.0.9',
    ]);
});
```

- [ ] **Step 9: Run the whole service test**

Run: `php artisan test --compact tests/Feature/DocumentServiceTest.php`
Expected: PASS. If the `UploadedFile::fake()->createWithContent('spec.pdf', ...)` size assertion is off, `assertSame` the byte length instead — `Document::query()->sole()->file_size` must equal `strlen('bytes')`.

- [ ] **Step 10: Commit**

```bash
git add app/Domain/Vault app/Providers bootstrap/app.php tests/Feature/DocumentServiceTest.php
git commit -m "feat(l4): add document storage rules with extension, size and filename guards"
```

---

### Task 3: Document lookup scoping, delete and the immutability wiring

**Files:**
- Create: `app/Domain/Vault/DocumentNotFound.php`
- Modify: `app/Domain/Vault/DocumentService.php` (add `findForApplication`, `delete`)
- Modify: `bootstrap/app.php` (map `DocumentNotFound` → `404`, `DocumentIsImmutable` → `409`)
- Test: `tests/Feature/DocumentDeleteTest.php`

**Interfaces:**
- Consumes: `DocumentService` (Task 2), `DocumentImmutability::assertModifiable(Document)` and `DocumentIsImmutable` (L1).
- Produces: `DocumentService::findForApplication(Application $application, string $documentId): Document`, `DocumentService::delete(Document $document, User $actor, ?string $ipAddress = null): void`.

- [ ] **Step 1: Write the failing delete test**

Create `tests/Feature/DocumentDeleteTest.php`:

```php
<?php

use App\Domain\Vault\DocumentIsImmutable;
use App\Domain\Vault\DocumentNotFound;
use App\Domain\Vault\DocumentService;
use App\Models\Application;
use App\Models\Document;
use App\Models\FilingRecord;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('finds a document only through its own application', function () {
    Storage::fake('vault');
    $owner = Application::query()->create(['title' => 'Owner']);
    $other = Application::query()->create(['title' => 'Other']);
    $service = app(DocumentService::class);

    $document = $service->store(
        $owner,
        UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
        User::factory()->director()->create(),
    );

    expect($service->findForApplication($owner, $document->id)->id)->toBe($document->id);

    expect(fn () => $service->findForApplication($other, $document->id))
        ->toThrow(DocumentNotFound::class);
});

it('deletes an unfiled document and its bytes', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Draft']);
    $service = app(DocumentService::class);
    $director = User::factory()->director()->create();

    $document = $service->store(
        $application,
        UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
        $director,
    );

    $service->delete($document, $director);

    expect(Document::query()->count())->toBe(0)
        ->and(Storage::disk('vault')->exists($document->content_ref))->toBeFalse()
        ->and($service->hasDocuments($application))->toBeFalse();
});

it('refuses to delete a document once the application is filed', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Filed']);
    $service = app(DocumentService::class);
    $director = User::factory()->director()->create();

    $document = $service->store(
        $application,
        UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
        $director,
    );

    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => '2026-10-01 00:00:00',
        'filed_by' => 'director@pdn.ac.lk',
    ]);

    expect(fn () => $service->delete($document, $director))->toThrow(DocumentIsImmutable::class);

    expect(Document::query()->count())->toBe(1)
        ->and(Storage::disk('vault')->exists($document->content_ref))->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/DocumentDeleteTest.php`
Expected: FAIL — `Call to undefined method App\Domain\Vault\DocumentService::findForApplication()`.

- [ ] **Step 3: Write `DocumentNotFound` and the two methods**

`app/Domain/Vault/DocumentNotFound.php`:

```php
<?php

namespace App\Domain\Vault;

use RuntimeException;

/** The document id does not exist under this application (HTTP 404). */
final class DocumentNotFound extends RuntimeException
{
    public static function forApplication(string $documentId, string $applicationId): self
    {
        return new self("Document {$documentId} not found for application {$applicationId}");
    }
}
```

In `DocumentService`, add `use App\Models\User;`-adjacent imports for the new classes and:

```php
    /**
     * @throws DocumentNotFound when the document does not belong to this application
     */
    public function findForApplication(Application $application, string $documentId): Document
    {
        return Document::query()
            ->where('application_id', $application->id)
            ->whereKey($documentId)
            ->first()
            ?? throw DocumentNotFound::forApplication($documentId, $application->id);
    }

    /**
     * @throws DocumentIsImmutable when the application is already filed
     */
    public function delete(Document $document, User $actor, ?string $ipAddress = null): void
    {
        $this->immutability->assertModifiable($document);

        $this->store->delete($document->content_ref);

        $application = Application::query()->find($document->application_id);

        $document->delete();

        $this->audit->record('delete_document', actor: $actor, application: $application, ipAddress: $ipAddress);
    }
```

Add `private readonly DocumentImmutability $immutability` to the constructor (inject the class directly; it is stateless).

- [ ] **Step 4: Map the two exceptions in `bootstrap/app.php`**

```php
        $exceptions->render(
            fn (DocumentNotFound $e) => response()->json(['message' => $e->getMessage()], 404),
        );

        $exceptions->render(
            fn (DocumentIsImmutable $e) => response()->json(['message' => $e->getMessage()], 409),
        );
```

- [ ] **Step 5: Run the delete test to verify it passes**

Run: `php artisan test --compact tests/Feature/DocumentDeleteTest.php tests/Feature/DocumentImmutabilityTest.php`
Expected: PASS (3 + 2 tests). The L1 immutability test must stay green — it calls `assertModifiable` directly.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Vault/DocumentNotFound.php app/Domain/Vault/DocumentService.php bootstrap/app.php tests/Feature/DocumentDeleteTest.php
git commit -m "feat(l4): scope document lookup to its application and gate deletion on filing"
```

---

### Task 4: Staff upload and list endpoints

**Files:**
- Create: `app/Http/Controllers/DocumentController.php`
- Modify: `routes/web.php` (two routes)
- Test: `tests/Feature/DocumentUploadEndpointTest.php`

**Interfaces:**
- Consumes: `DocumentService` (Task 2), `ApplicationIntakeService::findOrFail` (L3).
- Produces: `POST /applications/{applicationId}/documents` → `200` document JSON; `GET /applications/{applicationId}/documents` → `200` list; route names `documents.store`, `documents.index`.

- [ ] **Step 1: Write the failing endpoint test**

Create `tests/Feature/DocumentUploadEndpointTest.php`:

```php
<?php

use App\Models\Application;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('uploads a document as staff', function (string $state) {
    Storage::fake('vault');
    $staff = User::factory()->{$state}()->create();
    $application = Application::query()->create(['title' => 'Upload']);

    $this->actingAs($staff)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('specification.pdf', '%PDF-1.4'),
    ])->assertOk()
        ->assertJsonPath('filename', 'specification.pdf')
        ->assertJsonPath('application_id', $application->id)
        ->assertJsonPath('uploaded_by', $staff->email);

    $document = Document::query()->sole();

    expect($document->file_size)->toBe(8)
        ->and(Storage::disk('vault')->exists($document->content_ref))->toBeTrue()
        ->and(Storage::disk('vault')->get($document->content_ref))->toBe('%PDF-1.4');
})->with(['director', 'md']);

it('requires a file, resolves the application last and applies the upload rules', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Rules']);

    // No file at all → validation 422, before any lookup.
    $this->actingAs($director)->postJson("/applications/{$application->id}/documents")
        ->assertStatus(422)->assertJsonValidationErrors('file');

    // Valid file, unknown application → 404.
    $this->actingAs($director)->post('/applications/unknown-app/documents', [
        'file' => UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
    ])->assertNotFound();

    // Disallowed extension → 422 with the allow-list in the message.
    $this->actingAs($director)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('evil.exe', 'bytes'),
    ])->assertStatus(422)
        ->assertJsonPath('message', "File type '.exe' not allowed. Accepted: .csv, .doc, .docx, .jpeg, .jpg, .pdf, .png, .txt, .xls, .xlsx");

    // Over the cap → 413.
    $this->actingAs($director)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'),
    ])->assertStatus(413)
        ->assertJsonPath('message', 'Document too large (max 10 MB)');

    expect(Document::query()->count())->toBe(0);
});

it('lists the documents of one application for staff only', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Listed']);

    foreach (['first.pdf', 'second.pdf'] as $name) {
        $this->actingAs($director)->post("/applications/{$application->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent($name, 'bytes'),
        ])->assertOk();
    }

    $listed = $this->actingAs($director)
        ->getJson("/applications/{$application->id}/documents")
        ->assertOk()
        ->json();

    expect($listed)->toHaveCount(2)
        ->and($listed[0]['filename'])->toBe('first.pdf')
        ->and($listed[1]['filename'])->toBe('second.pdf');

    $this->actingAs(User::factory()->create())->getJson("/applications/{$application->id}/documents")
        ->assertForbidden();
    $this->getJson("/applications/{$application->id}/documents")->assertUnauthorized();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/DocumentUploadEndpointTest.php`
Expected: FAIL — `404` on the upload route (route not defined).

- [ ] **Step 3: Write the controller and routes**

`app/Http/Controllers/DocumentController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Vault\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller: validates the upload envelope and delegates to
 * App\Domain\Vault. Storage rules live in the domain, never here.
 */
final class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly ApplicationIntakeService $intake,
    ) {}

    public function store(Request $request, string $applicationId): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
        ]);

        return response()->json($this->documents->store(
            $this->intake->findOrFail($applicationId),
            $validated['file'],
            $request->user(),
            $request->ip(),
        ));
    }

    public function index(string $applicationId): JsonResponse
    {
        return response()->json($this->documents->listFor($this->intake->findOrFail($applicationId)));
    }
}
```

In `routes/web.php`, add after the office-action route (order matters only for same-segment-count paths; these are distinct):

```php
Route::post('/applications/{applicationId}/documents', [DocumentController::class, 'store'])
    ->middleware(['auth', 'role:director,md'])
    ->name('documents.store');

Route::get('/applications/{applicationId}/documents', [DocumentController::class, 'index'])
    ->middleware(['auth', 'role:director,md'])
    ->name('documents.index');
```

with `use App\Http\Controllers\DocumentController;` added to the imports.

- [ ] **Step 4: Run the endpoint test to verify it passes**

Run: `php artisan test --compact tests/Feature/DocumentUploadEndpointTest.php`
Expected: PASS (2 dataset cases + 2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/DocumentController.php routes/web.php tests/Feature/DocumentUploadEndpointTest.php
git commit -m "feat(l4): add staff document upload and listing endpoints"
```

---

### Task 5: Missing-documents worklist, per-document download and delete endpoints

**Files:**
- Modify: `app/Http/Controllers/DocumentController.php` (`missing`, `download`, `destroy`)
- Modify: `routes/web.php` (three routes)
- Test: `tests/Feature/DocumentStaffEndpointsTest.php`

**Interfaces:**
- Consumes: `DocumentService::findForApplication`, `delete` (Task 3), `DocumentFilename` (Task 2).
- Produces: `GET /applications/missing-documents` → `200` application list; `GET /applications/{applicationId}/documents/{documentId}/download` → `200` bytes with `Content-Disposition`; `DELETE /applications/{applicationId}/documents/{documentId}` → `204`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DocumentStaffEndpointsTest.php`:

```php
<?php

use App\Models\Application;
use App\Models\Document;
use App\Models\FilingRecord;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('lists only granted applications that have no documents', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();

    $grantedEmpty = Application::query()->create(['title' => 'Granted empty', 'status' => 'GRANTED']);
    $grantedWithDoc = Application::query()->create(['title' => 'Granted with doc', 'status' => 'GRANTED']);
    Application::query()->create(['title' => 'Filed empty', 'status' => 'FILED']);

    $this->actingAs($director)->post("/applications/{$grantedWithDoc->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('patent.pdf', 'bytes'),
    ])->assertOk();

    $ids = collect($this->actingAs($director)->getJson('/applications/missing-documents')->assertOk()->json())
        ->pluck('id');

    expect($ids)->toContain($grantedEmpty->id)
        ->and($ids)->not->toContain($grantedWithDoc->id)
        ->and($ids)->toHaveCount(1);
});

it('downloads a single document with a sanitized disposition', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Disposition']);

    $this->actingAs($director)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('../../../evil.pdf', 'payload'),
    ])->assertOk();

    $document = Document::query()->sole();

    $response = $this->actingAs($director)
        ->get("/applications/{$application->id}/documents/{$document->id}/download")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $disposition = $response->headers->get('content-disposition');

    expect($disposition)->toBe('attachment; filename="evil.pdf"')
        ->and(substr_count($disposition, '"'))->toBe(2)
        ->and($disposition)->not->toContain('..', "\r", "\n");
});

it('returns 404 for a document scoped to another application', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $owner = Application::query()->create(['title' => 'Owner']);
    $other = Application::query()->create(['title' => 'Other']);

    $this->actingAs($director)->post("/applications/{$owner->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
    ])->assertOk();

    $document = Document::query()->sole();

    $this->actingAs($director)
        ->get("/applications/{$other->id}/documents/{$document->id}/download")
        ->assertNotFound();

    $this->actingAs($director)
        ->delete("/applications/{$other->id}/documents/{$document->id}")
        ->assertNotFound();
});

it('deletes an unfiled document as staff and refuses a filed one', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Deletes']);

    $this->actingAs($director)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
    ])->assertOk();

    $document = Document::query()->sole();

    $this->actingAs($director)
        ->delete("/applications/{$application->id}/documents/{$document->id}")
        ->assertNoContent();

    expect(Document::query()->count())->toBe(0);

    $this->actingAs($director)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
    ])->assertOk();

    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => '2026-10-01 00:00:00',
        'filed_by' => 'director@pdn.ac.lk',
    ]);

    $this->actingAs($director)
        ->delete("/applications/{$application->id}/documents/".Document::query()->sole()->id)
        ->assertStatus(409)
        ->assertJsonPath('message', 'Document '.Document::query()->sole()->id.' is immutable: its application has been filed');

    expect(Document::query()->count())->toBe(1);
});

it('gates the staff document routes', function () {
    $application = Application::query()->create(['title' => 'Gated']);
    $user = User::factory()->create();

    $this->getJson('/applications/missing-documents')->assertUnauthorized();
    $this->actingAs($user)->getJson('/applications/missing-documents')->assertForbidden();

    $this->actingAs($user)
        ->delete("/applications/{$application->id}/documents/11111111-1111-1111-1111-111111111111")
        ->assertForbidden();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/DocumentStaffEndpointsTest.php`
Expected: FAIL — missing route `/applications/missing-documents` (404).

- [ ] **Step 3: Add the three controller methods**

In `DocumentController`:

```php
    public function missing(): JsonResponse
    {
        return response()->json($this->documents->missingGrantedDocuments());
    }

    public function download(string $applicationId, string $documentId): Response
    {
        $application = $this->intake->findOrFail($applicationId);
        $document = $this->documents->findForApplication($application, $documentId);
        $bytes = $this->documents->bytesFor($document);

        return response($bytes, 200, [
            'Content-Type' => DocumentFilename::mimeType($document->filename),
            'Content-Disposition' => 'attachment; filename="'.DocumentFilename::safe($document->filename).'"',
        ]);
    }

    public function destroy(Request $request, string $applicationId, string $documentId): Response
    {
        $application = $this->intake->findOrFail($applicationId);

        $this->documents->delete(
            $this->documents->findForApplication($application, $documentId),
            $request->user(),
            $request->ip(),
        );

        return response()->noContent();
    }
```

Add `use App\Domain\Vault\DocumentFilename;` and `use Symfony\Component\HttpFoundation\Response;` to the controller. `response()->noContent()`, `response($bytes, ...)` and `response()->json(...)` are all Symfony `Response` subtypes, so one import covers the three new return types.

`DocumentService` gains the two supporting methods:

```php
    /**
     * @return Collection<int, Application>
     */
    public function missingGrantedDocuments(): Collection
    {
        return Application::query()
            ->where('status', ApplicationStatus::GRANTED->value)
            ->whereDoesntHave('documents')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @throws DocumentContentMissing when the bytes are gone from the store
     */
    public function bytesFor(Document $document): string
    {
        return $this->store->get($document->content_ref)
            ?? throw new DocumentContentMissing("Document content missing for {$document->id}");
    }
```

`whereDoesntHave('documents')` needs an `Application::documents()` relation. Add to `app/Models/Application.php`:

```php
    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
```

with `use Illuminate\Database\Eloquent\Relations\HasMany;`.

Create `app/Domain/Vault/DocumentContentMissing.php` (`final class DocumentContentMissing extends RuntimeException {}`) and map it to `404` in `bootstrap/app.php`:

```php
        $exceptions->render(
            fn (DocumentContentMissing $e) => response()->json(['message' => $e->getMessage()], 404),
        );
```

- [ ] **Step 4: Add the routes**

```php
Route::get('/applications/missing-documents', [DocumentController::class, 'missing'])
    ->middleware(['auth', 'role:director,md'])
    ->name('documents.missing');

Route::get('/applications/{applicationId}/documents/{documentId}/download', [DocumentController::class, 'download'])
    ->middleware(['auth', 'role:director,md'])
    ->name('documents.download');

Route::delete('/applications/{applicationId}/documents/{documentId}', [DocumentController::class, 'destroy'])
    ->middleware(['auth', 'role:director,md'])
    ->name('documents.destroy');
```

`missing-documents` must be declared **before** `documents.index` is declared? No — `/applications/missing-documents` has two segments and `/applications/{applicationId}/documents` has three, so they cannot collide. Keep the group together for readability.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/DocumentStaffEndpointsTest.php`
Expected: PASS (5 tests). The 409 assertion expects the message from `DocumentImmutability`; if that message differs, assert only the status code and adjust this step's expectation (do not change the domain message to fit the test).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/DocumentController.php app/Domain/Vault app/Models/Application.php routes/web.php bootstrap/app.php tests/Feature/DocumentStaffEndpointsTest.php
git commit -m "feat(l4): add missing-documents worklist and staff document download/delete"
```

---

### Task 6: Inventor granted-patent download (single file and ZIP)

**Files:**
- Create: `app/Domain/Vault/GrantedPatentService.php`
- Create: `app/Domain/Vault/PatentNotGranted.php`
- Create: `app/Domain/Vault/NotYourPatent.php`
- Create: `app/Domain/Vault/DocumentPending.php`
- Create: `app/Http/Controllers/GrantedPatentController.php`
- Modify: `routes/web.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/GrantedDownloadTest.php`

**Interfaces:**
- Consumes: `DocumentService::listFor`, `bytesFor`, `DocumentFilename` (Tasks 2, 5).
- Produces: `GrantedPatentService::downloadPayload(Application $application, User $viewer): GrantedDownload` where `GrantedDownload` carries `?string $singleBytes`, `?string $zipPath`, `string $downloadName`, `string $contentType`; `GET /applications/{applicationId}/download` → `200`.

Deliberate design note: the ZIP must be built on disk (`ZipArchive` cannot write to memory), so `downloadPayload` returns a temp file path for the multi-document case and the controller streams it with `deleteFileAfterSend()`. Exceptions carry the HTTP mapping.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/GrantedDownloadTest.php`:

```php
<?php

use App\Models\Application;
use App\Models\Document;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function grantedApplication(array $attributes = []): Application
{
    return Application::query()->create(array_merge([
        'title' => 'Granted invention',
        'status' => 'GRANTED',
        'inventor_email' => 'owner@pdn.ac.lk',
    ], $attributes));
}

function uploadAs(User $staff, Application $application, string $name, string $contents): Document
{
    test()->actingAs($staff)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent($name, $contents),
    ])->assertOk();

    return Document::query()->where('filename', $name)->sole();
}

it('lets the owning inventor download the granted patent', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $application = grantedApplication();

    uploadAs($director, $application, 'patent.pdf', 'granted bytes');

    $response = $this->actingAs($owner)
        ->get("/applications/{$application->id}/download")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $disposition = $response->headers->get('content-disposition');

    expect($response->getContent())->toBe('granted bytes')
        ->and($disposition)->toStartWith('attachment; filename="patent_granted_')
        ->and($disposition)->toEndWith('.pdf"')
        ->and($response->headers->get('x-downloaded-at'))->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'download_granted_patent',
        'user_id' => $owner->id,
        'application_id' => $application->id,
    ]);
});

it('zips every document of a multi-document granted patent', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $application = grantedApplication();

    uploadAs($director, $application, 'patent.pdf', 'first bytes');
    uploadAs($director, $application, 'drawings.pdf', 'second bytes');

    $response = $this->actingAs($owner)
        ->get("/applications/{$application->id}/download")
        ->assertOk()
        ->assertHeader('content-type', 'application/zip');

    expect($response->headers->get('content-disposition'))
        ->toStartWith('attachment; filename="'.$application->id.'_granted_')
        ->toEndWith('.zip"');

    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());

    expect($zip->numFiles)->toBe(2)
        ->and($zip->getFromName('patent.pdf'))->toBe('first bytes')
        ->and($zip->getFromName('drawings.pdf'))->toBe('second bytes');

    $zip->close();
});

it('refuses downloads that are not granted or not owned', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $other = User::factory()->create(['email' => 'other@pdn.ac.lk']);

    $draft = Application::query()->create(['title' => 'Draft', 'inventor_email' => 'owner@pdn.ac.lk']);
    $granted = grantedApplication();

    $this->actingAs($owner)->getJson("/applications/{$draft->id}/download")
        ->assertForbidden();

    $this->actingAs($other)->getJson("/applications/{$granted->id}/download")
        ->assertForbidden();

    $this->actingAs($director)->getJson("/applications/{$draft->id}/download")
        ->assertForbidden();

    $this->getJson("/applications/{$granted->id}/download")->assertUnauthorized();
});

it('reports document_pending for a granted patent with no documents', function () {
    Storage::fake('vault');
    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $application = grantedApplication();

    $this->actingAs($owner)->getJson("/applications/{$application->id}/download")
        ->assertStatus(409)
        ->assertJsonPath('code', 'document_pending')
        ->assertJsonPath('message', 'Patent is GRANTED but the document has not been uploaded yet. You can request it via POST /applications/{application_id}/request-document.');
});

it('still returns a zip when one document has lost its bytes', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $application = grantedApplication();

    uploadAs($director, $application, 'patent.pdf', 'first bytes');
    $lost = uploadAs($director, $application, 'lost.pdf', 'second bytes');

    Storage::disk('vault')->delete($lost->content_ref);

    $response = $this->actingAs($owner)->get("/applications/{$application->id}/download")->assertOk();

    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());

    expect($zip->numFiles)->toBe(1)
        ->and($zip->getFromName('patent.pdf'))->toBe('first bytes');

    $zip->close();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/GrantedDownloadTest.php`
Expected: FAIL — the route does not exist (`404`).

- [ ] **Step 3: Write the domain service and its result type**

`app/Domain/Vault/GrantedDownload.php`:

```php
<?php

namespace App\Domain\Vault;

/**
 * A prepared granted-patent download: either inline bytes or a temp ZIP.
 */
final readonly class GrantedDownload
{
    public function __construct(
        public string $downloadName,
        public string $contentType,
        public ?string $bytes = null,
        public ?string $zipPath = null,
    ) {}
}
```

`app/Domain/Vault/GrantedPatentService.php`:

```php
<?php

namespace App\Domain\Vault;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Authorization\Role;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Models\Application;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use ZipArchive;

/**
 * Inventor-facing granted-patent download — port of
 * `app/api/routes.py::download_granted_patent`. One document streams as
 * itself; two or more become a ZIP. Ownership (invariant 6) and the
 * GRANTED gate apply to every role.
 */
final class GrantedPatentService
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @throws NotYourPatent
     * @throws PatentNotGranted
     * @throws DocumentPending
     */
    public function downloadPayload(
        Application $application,
        User $viewer,
        ?string $ipAddress = null,
    ): GrantedDownload {
        $this->assertDownloadable($application, $viewer);

        $documents = $this->documents->listFor($application);

        if ($documents->isEmpty()) {
            throw DocumentPending::forApplication($application->id);
        }

        $this->audit->record(
            'download_granted_patent',
            actor: $viewer,
            application: $application,
            ipAddress: $ipAddress,
        );

        $stamp = Carbon::now()->utc()->format('Ymd\THis\Z');

        if ($documents->count() === 1) {
            $document = $documents->first();

            return new GrantedDownload(
                downloadName: $this->grantedName($document->filename, $stamp),
                contentType: DocumentFilename::mimeType($document->filename),
                bytes: $this->documents->bytesFor($document),
            );
        }

        return new GrantedDownload(
            downloadName: "{$application->id}_granted_{$stamp}.zip",
            contentType: 'application/zip',
            zipPath: $this->zip($documents),
        );
    }

    /**
     * @throws NotYourPatent
     * @throws PatentNotGranted
     */
    private function assertDownloadable(Application $application, User $viewer): void
    {
        if (Role::from($viewer->role) === Role::USER && $application->inventor_email !== $viewer->email) {
            throw new NotYourPatent('Not your patent');
        }

        if (ApplicationStatus::from($application->status) !== ApplicationStatus::GRANTED) {
            throw new PatentNotGranted('Download available only for GRANTED patents');
        }
    }

    private function grantedName(string $filename, string $stamp): string
    {
        $safe = DocumentFilename::safe($filename);
        $extension = DocumentRules::extensionOf($safe);
        $stem = $extension === '' ? $safe : substr($safe, 0, -(strlen($extension) + 1));

        return $extension === ''
            ? "{$stem}_granted_{$stamp}"
            : "{$stem}_granted_{$stamp}.{$extension}";
    }

    /**
     * @param  Collection<int, Document>  $documents
     */
    private function zip(Collection $documents): string
    {
        // ZipArchive can only write to a real file, so build one and hand
        // ownership to the response (deleteFileAfterSend).
        $scratch = (string) tempnam(sys_get_temp_dir(), 'blitto-docs');
        $path = $scratch.'.zip';
        unlink($scratch);

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($documents as $document) {
            $bytes = $this->documents->tryBytesFor($document);

            if ($bytes !== null) {
                $zip->addFromString(DocumentFilename::safe($document->filename), $bytes);
            }
        }

        $zip->close();

        return $path;
    }
}
```

`DocumentService` gains `tryBytesFor` next to `bytesFor`:

```php
    /**
     * Bytes for delivery, or null when the store no longer has them.
     */
    public function tryBytesFor(Document $document): ?string
    {
        return $this->store->get($document->content_ref);
    }
```

and `bytesFor` becomes:

```php
    public function bytesFor(Document $document): string
    {
        return $this->tryBytesFor($document)
            ?? throw new DocumentContentMissing("Document content missing for {$document->id}");
    }
```

- [ ] **Step 4: Write the three exceptions and map them**

```php
<?php

namespace App\Domain\Vault;

use RuntimeException;

/** The patent is not GRANTED yet (HTTP 403 on download). */
final class PatentNotGranted extends RuntimeException {}
```

```php
<?php

namespace App\Domain\Vault;

use RuntimeException;

/** A plain user asked for a patent they do not own (HTTP 403). */
final class NotYourPatent extends RuntimeException {}
```

```php
<?php

namespace App\Domain\Vault;

use RuntimeException;

/** GRANTED with no documents: 409 with a machine-readable code. */
final class DocumentPending extends RuntimeException
{
    public static function forApplication(string $applicationId): self
    {
        return new self(
            'Patent is GRANTED but the document has not been uploaded yet. '
            .'You can request it via POST /applications/{application_id}/request-document.'
        );
    }
}
```

In `bootstrap/app.php`:

```php
        $exceptions->render(
            fn (NotYourPatent $e) => response()->json(['message' => $e->getMessage()], 403),
        );

        $exceptions->render(
            fn (PatentNotGranted $e) => response()->json(['message' => $e->getMessage()], 403),
        );

        $exceptions->render(
            fn (DocumentPending $e) => response()->json([
                'message' => $e->getMessage(),
                'code' => 'document_pending',
            ], 409),
        );
```

- [ ] **Step 5: Write the controller and route**

`app/Http/Controllers/GrantedPatentController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Vault\GrantedPatentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin controller: the GRANTED/ownership rules and the ZIP assembly live in
 * App\Domain\Vault\GrantedPatentService.
 */
final class GrantedPatentController extends Controller
{
    public function __construct(
        private readonly GrantedPatentService $granted,
        private readonly ApplicationIntakeService $intake,
    ) {}

    public function download(Request $request, string $applicationId): Response
    {
        $download = $this->granted->downloadPayload(
            $this->intake->findOrFail($applicationId),
            $request->user(),
            $request->ip(),
        );

        $headers = [
            'Content-Type' => $download->contentType,
            'Content-Disposition' => 'attachment; filename="'.$download->downloadName.'"',
            'X-Downloaded-At' => now()->utc()->toIso8601String(),
        ];

        if ($download->zipPath !== null) {
            return response()->download($download->zipPath, $download->downloadName, $headers)
                ->deleteFileAfterSend();
        }

        return response($download->bytes, 200, $headers);
    }
}
```

Add to `routes/web.php`:

```php
Route::get('/applications/{applicationId}/download', [GrantedPatentController::class, 'download'])
    ->middleware('auth')
    ->name('documents.granted-download');
```

(`BinaryFileResponse` is imported for the `response()->download()` return type in the docblock; drop the import if Pint flags it as unused.)

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/GrantedDownloadTest.php`
Expected: PASS (5 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Vault app/Http/Controllers/GrantedPatentController.php routes/web.php bootstrap/app.php tests/Feature/GrantedDownloadTest.php
git commit -m "feat(l4): add granted-patent download with single-file and zip delivery"
```

---

### Task 7: `request-document` for a pending granted patent

**Files:**
- Create: `app/Domain/Vault/DocumentRequestRefused.php`
- Create: `app/Domain/Vault/DocumentAlreadyRequested.php`
- Modify: `app/Domain/Vault/GrantedPatentService.php` (`requestDocument`)
- Modify: `app/Http/Controllers/GrantedPatentController.php`, `routes/web.php`, `bootstrap/app.php`
- Test: `tests/Feature/DocumentRequestTest.php`

**Interfaces:**
- Consumes: `GrantedPatentService`, `NotificationService::send`, `AuditRecorder`, `config('blitto.bootstrap_admin_email')`.
- Produces: `GrantedPatentService::requestDocument(Application $application, User $viewer, ?string $ipAddress = null): void`; `POST /applications/{applicationId}/request-document` → `200 {"status":"requested","application_id":...}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DocumentRequestTest.php`:

```php
<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('accepts one document request per user and notifies the admin', function () {
    Storage::fake('vault');
    config(['blitto.bootstrap_admin_email' => 'admin@pdn.ac.lk']);

    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $application = Application::query()->create([
        'title' => 'Pending patent',
        'status' => 'GRANTED',
        'inventor_email' => 'owner@pdn.ac.lk',
    ]);

    $this->actingAs($owner)
        ->postJson("/applications/{$application->id}/request-document")
        ->assertOk()
        ->assertJsonPath('status', 'requested')
        ->assertJsonPath('application_id', $application->id);

    $notification = Notification::query()->sole();

    expect($notification->recipient_email)->toBe('admin@pdn.ac.lk')
        ->and($notification->kind)->toBe('document_request');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'document_requested',
        'user_id' => $owner->id,
        'application_id' => $application->id,
    ]);

    // A second request by the same user is refused for good.
    $this->actingAs($owner)
        ->postJson("/applications/{$application->id}/request-document")
        ->assertStatus(429)
        ->assertJsonPath('message', 'Document already requested');

    expect(Notification::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'document_requested')->count())->toBe(1);
});

it('fails back to the configured admin address when none is set', function () {
    Storage::fake('vault');
    config(['blitto.bootstrap_admin_email' => '']);

    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $application = Application::query()->create([
        'title' => 'Fallback',
        'status' => 'GRANTED',
        'inventor_email' => 'owner@pdn.ac.lk',
    ]);

    $this->actingAs($owner)->postJson("/applications/{$application->id}/request-document")->assertOk();

    expect(Notification::query()->sole()->recipient_email)->toBe('md@pdn.ac.lk');
});

it('refuses requests for patents that are not granted or already documented', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $other = User::factory()->create(['email' => 'other@pdn.ac.lk']);

    $draft = Application::query()->create(['title' => 'Draft', 'inventor_email' => 'owner@pdn.ac.lk']);
    $granted = Application::query()->create([
        'title' => 'Documented',
        'status' => 'GRANTED',
        'inventor_email' => 'owner@pdn.ac.lk',
    ]);

    $this->actingAs($director)->post("/applications/{$granted->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('patent.pdf', 'bytes'),
    ])->assertOk();

    // Not GRANTED.
    $this->actingAs($owner)->postJson("/applications/{$draft->id}/request-document")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Patent is not GRANTED');

    // Already documented.
    $this->actingAs($owner)->postJson("/applications/{$granted->id}/request-document")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Document already available');

    // Someone else's patent.
    $this->actingAs($other)->postJson("/applications/{$draft->id}/request-document")
        ->assertForbidden();

    // Nothing was written by the refusals.
    expect(Notification::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'document_requested')->count())->toBe(0);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/DocumentRequestTest.php`
Expected: FAIL — route not defined (`404`).

- [ ] **Step 3: Write the two exceptions and the service method**

```php
<?php

namespace App\Domain\Vault;

use RuntimeException;

/** The request cannot be honoured in the patent's current state (HTTP 409). */
final class DocumentRequestRefused extends RuntimeException {}
```

```php
<?php

namespace App\Domain\Vault;

use RuntimeException;

/** This user already has an open document request for the patent (HTTP 429). */
final class DocumentAlreadyRequested extends RuntimeException {}
```

In `GrantedPatentService` (add `NotificationService`, `AuditLog` and `config()` usage):

```php
    /**
     * @throws NotYourPatent
     * @throws DocumentRequestRefused
     * @throws DocumentAlreadyRequested
     */
    public function requestDocument(Application $application, User $viewer, ?string $ipAddress = null): void
    {
        if (Role::from($viewer->role) === Role::USER && $application->inventor_email !== $viewer->email) {
            throw new NotYourPatent('Not your patent');
        }

        if (ApplicationStatus::from($application->status) !== ApplicationStatus::GRANTED) {
            throw new DocumentRequestRefused('Patent is not GRANTED');
        }

        if ($this->documents->hasDocuments($application)) {
            throw new DocumentRequestRefused('Document already available');
        }

        if ($this->alreadyRequested($application, $viewer)) {
            throw new DocumentAlreadyRequested('Document already requested');
        }

        $this->audit->record('document_requested', actor: $viewer, application: $application, ipAddress: $ipAddress);

        $this->notifications->send(
            $this->adminRecipient(),
            "Document requested for {$application->id}",
            "{$viewer->email} requested the granted patent document for application {$application->id} ({$application->title}).",
            'document_request',
        );
    }

    private function alreadyRequested(Application $application, User $viewer): bool
    {
        return AuditLog::query()
            ->where('action', 'document_requested')
            ->where('user_id', $viewer->id)
            ->where('application_id', $application->id)
            ->exists();
    }

    private function adminRecipient(): string
    {
        $configured = (string) config('blitto.bootstrap_admin_email');

        return $configured !== '' ? $configured : 'md@pdn.ac.lk';
    }
```

Add `private readonly NotificationService $notifications` to the constructor and the `AuditLog` import.

- [ ] **Step 4: Add the controller method, route and mappings**

In `GrantedPatentController`:

```php
    public function requestDocument(Request $request, string $applicationId): JsonResponse
    {
        $application = $this->intake->findOrFail($applicationId);

        $this->granted->requestDocument($application, $request->user(), $request->ip());

        return response()->json([
            'status' => 'requested',
            'application_id' => $application->id,
        ]);
    }
```

(import `Illuminate\Http\JsonResponse`), the route:

```php
Route::post('/applications/{applicationId}/request-document', [GrantedPatentController::class, 'requestDocument'])
    ->middleware('auth')
    ->name('documents.request');
```

and the renders:

```php
        $exceptions->render(
            fn (DocumentRequestRefused $e) => response()->json(['message' => $e->getMessage()], 409),
        );

        $exceptions->render(
            fn (DocumentAlreadyRequested $e) => response()->json(['message' => $e->getMessage()], 429),
        );
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/DocumentRequestTest.php`
Expected: PASS (3 tests). Confirm `config('blitto.bootstrap_admin_email')` is the real key name in `config/blitto.php`; if it differs, use the real one in the service and the test together.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Vault app/Http/Controllers/GrantedPatentController.php routes/web.php bootstrap/app.php tests/Feature/DocumentRequestTest.php
git commit -m "feat(l4): add rate-limited document request flow for granted patents"
```

---

### Task 8: Grant-without-document warning and admin notification

**Files:**
- Modify: `app/Domain/Filing/FilingWorkflowService.php` (inject `DocumentService`; warn + notify in `markGranted`; add `grantWarning`)
- Modify: `app/Http/Controllers/FilingController.php` (`grant` returns the warning when present)
- Test: `tests/Feature/GrantWithoutDocumentTest.php`

**Interfaces:**
- Consumes: `DocumentService::hasDocuments`, `NotificationService::send` (Tasks 2, 7).
- Produces: `FilingWorkflowService::grantWarning(Application $application): ?string`; the `POST /admin/filing/{id}/grant` body gains a top-level `warning` key **only** when the granted patent has no documents.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/GrantWithoutDocumentTest.php`:

```php
<?php

use App\Models\Application;
use App\Models\FilingRecord;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('warns and notifies the admin when granting without a document', function () {
    Storage::fake('vault');
    config(['blitto.bootstrap_admin_email' => 'admin@pdn.ac.lk']);

    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'No document', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/file")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/acknowledge")->assertOk();

    $response = $this->actingAs($md)
        ->postJson("/admin/filing/{$application->id}/grant?patent_number=LK/PAT/2026/1")
        ->assertOk()
        ->assertJsonPath('status', 'GRANTED');

    expect($response->json('warning'))->toContain('no document');

    $this->assertDatabaseHas('notifications', [
        'recipient_email' => 'admin@pdn.ac.lk',
        'kind' => 'grant_without_document',
    ]);
});

it('grants without a warning once a document exists', function () {
    Storage::fake('vault');
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Documented', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/file")->assertOk();
    $this->actingAs($md)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('patent.pdf', 'bytes'),
    ])->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/acknowledge")->assertOk();

    $response = $this->actingAs($md)
        ->postJson("/admin/filing/{$application->id}/grant?patent_number=LK/PAT/2026/2")
        ->assertOk();

    expect($response->json('warning'))->toBeNull()
        ->and(Notification::query()->where('kind', 'grant_without_document')->count())->toBe(0);

    expect(FilingRecord::query()->sole()->patent_number)->toBe('LK/PAT/2026/2');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/GrantWithoutDocumentTest.php`
Expected: FAIL — `warning` is null in the first test.

- [ ] **Step 3: Add the domain warning**

In `FilingWorkflowService`, inject `private readonly DocumentService $documents` (import `App\Domain\Vault\DocumentService`), and in `markGranted` after the `notifyInventors` call:

```php
        $warning = $this->grantWarning($application);

        if ($warning !== null) {
            $this->notifications->send(
                (string) (config('blitto.bootstrap_admin_email') ?: 'md@pdn.ac.lk'),
                "Patent {$application->id} granted without a document",
                $warning,
                'grant_without_document',
            );
        }
```

and the public query:

```php
    /**
     * A granted patent with no document cannot be downloaded yet. Returns the
     * warning a caller should surface, or null when the download will work.
     */
    public function grantWarning(Application $application): ?string
    {
        if (ApplicationStatus::from($application->status) !== ApplicationStatus::GRANTED) {
            return null;
        }

        if ($this->documents->hasDocuments($application)) {
            return null;
        }

        return "Patent {$application->id} was granted but has no document yet; the inventor cannot download it until one is uploaded.";
    }
```

- [ ] **Step 4: Surface it in the grant response**

In `FilingController::grant`:

```php
        $application = $this->filing->markGranted(
            $this->intake->findOrFail($applicationId),
            (string) $validated['patent_number'],
            $request->user(),
        );

        $payload = $application->toArray();

        if (($warning = $this->filing->grantWarning($application)) !== null) {
            $payload['warning'] = $warning;
        }

        return response()->json($payload);
```

- [ ] **Step 5: Run the grant tests**

Run: `php artisan test --compact tests/Feature/GrantWithoutDocumentTest.php tests/Feature/FilingEndpointTest.php tests/Feature/FilingOutcomeTest.php tests/Feature/FilingWorkflowTest.php`
Expected: PASS. The L3 grant assertions read top-level keys, so the added `warning` key does not disturb them.

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Filing/FilingWorkflowService.php app/Http/Controllers/FilingController.php tests/Feature/GrantWithoutDocumentTest.php
git commit -m "feat(l4): warn and notify admins when a patent is granted without a document"
```

---

### Task 9: Journeys J1/J2/J4, endpoint matrix, docs and the whole suite

**Files:**
- Create: `tests/Feature/DocumentJourneyTest.php`
- Create: `tests/Feature/L4RbacMatrixTest.php`
- Create: `docs/adr/0004-documents-stored-in-the-clear.md`
- Modify: `BIGGER_PICTURE.md` (§6 L4 row, §9 status)
- Modify: `.github/workflows/*.yml` (the Laravel job's `setup-php` step gains `ini-values`)
- Modify: this plan (tick every box)

**Interfaces:**
- Consumes: everything above.
- Produces: end-to-end proof for journeys J1 (grant → inventor download), J2 (pending request), J4 (multi-document ZIP), an endpoint matrix for every L4 route, and a roadmap marked L4-done.

- [ ] **Step 1: Write the journey test**

Create `tests/Feature/DocumentJourneyTest.php`:

```php
<?php

use App\Models\Application;
use App\Models\Document;
use App\Models\Inventor;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

it('runs the J1 grant and download journey', function () {
    Storage::fake('vault');
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $inventor = User::factory()->create(['email' => 'j1@pdn.ac.lk']);

    $applicationId = $this->actingAs($md)->postJson('/applications', [
        'title' => 'J1 Widget',
        'inventor_name' => 'J One',
        'inventor_email' => 'j1@pdn.ac.lk',
    ])->assertOk()->json('id');

    // A document may be added after filing — this is how the granted
    // patent reaches the vault.
    $this->actingAs($md)->post("/applications/{$applicationId}/documents", [
        'file' => UploadedFile::fake()->createWithContent('patent.pdf', 'PATENT'),
    ])->assertOk();

    $this->actingAs($md)->postJson("/admin/filing/{$applicationId}/file")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$applicationId}/acknowledge")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$applicationId}/grant?patent_number=LK/PAT/2026/77")
        ->assertOk()
        ->assertJsonPath('status', 'GRANTED');

    $download = $this->actingAs($inventor)->get("/applications/{$applicationId}/download")->assertOk();

    expect($download->getContent())->toBe('PATENT')
        ->and($download->headers->get('x-downloaded-at'))->not->toBeNull();

    $this->getJson("/applications/{$applicationId}/download")->assertUnauthorized();
});

it('runs the J2 pending document request journey', function () {
    Storage::fake('vault');
    config(['blitto.bootstrap_admin_email' => 'admin@pdn.ac.lk']);

    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $inventor = User::factory()->create(['email' => 'j2@pdn.ac.lk']);

    $application = Application::query()->create([
        'title' => 'J2 Widget',
        'inventor_email' => 'j2@pdn.ac.lk',
    ]);

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/file")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/acknowledge")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/grant?patent_number=LK/PAT/2026/78")->assertOk();

    // The inventor sees the pending state, cannot download, then requests it.
    $this->actingAs($inventor)->getJson("/applications/{$application->id}/download")
        ->assertStatus(409)
        ->assertJsonPath('code', 'document_pending');

    $this->actingAs($inventor)->postJson("/applications/{$application->id}/request-document")->assertOk();

    $this->assertDatabaseHas('notifications', ['kind' => 'document_request']);

    // Staff answer the request by uploading the patent document.
    $this->actingAs($md)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('grant.pdf', 'GRANT'),
    ])->assertOk();

    $this->actingAs($inventor)->get("/applications/{$application->id}/download")
        ->assertOk();

    expect(collect($this->actingAs($md)->getJson('/applications/missing-documents')->json()))
        ->toBeEmpty();
});

it('runs the J4 multi-document zip journey', function () {
    Storage::fake('vault');
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $inventor = User::factory()->create(['email' => 'j4@pdn.ac.lk']);

    $application = Application::query()->create([
        'title' => 'J4 Widget',
        'status' => 'GRANTED',
        'inventor_email' => 'j4@pdn.ac.lk',
    ]);

    Inventor::query()->create([
        'application_id' => $application->id,
        'inventor_name' => 'J Four',
        'inventor_email' => 'j4@pdn.ac.lk',
    ]);

    foreach (['patent.pdf' => 'ONE', 'drawings.pdf' => 'TWO'] as $name => $contents) {
        $this->actingAs($md)->post("/applications/{$application->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent($name, $contents),
        ])->assertOk();
    }

    expect(Document::query()->count())->toBe(2)
        ->and(Notification::query()->where('kind', 'download_ready')->count())->toBe(1);

    $response = $this->actingAs($inventor)
        ->get("/applications/{$application->id}/download")
        ->assertOk()
        ->assertHeader('content-type', 'application/zip');

    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());

    expect($zip->getFromName('patent.pdf'))->toBe('ONE')
        ->and($zip->getFromName('drawings.pdf'))->toBe('TWO');

    $zip->close();
});
```

- [ ] **Step 2: Run the journeys**

Run: `php artisan test --compact tests/Feature/DocumentJourneyTest.php`
Expected: PASS (3 tests). If the J1 filing chain reports a lifecycle violation, compare it with `tests/Feature/FilingEndpointTest.php`'s known-good chain (`file` → `acknowledge` → `grant`) — that is the authoritative sequence, not a hand-rolled combination.

- [ ] **Step 3: Write the L4 endpoint matrix**

Create `tests/Feature/L4RbacMatrixTest.php`:

```php
<?php

use App\Models\Application;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('gates every L4 endpoint against guests and plain users', function () {
    Storage::fake('vault');

    $director = User::factory()->director()->create();
    $application = Application::query()->create([
        'title' => 'L4 Matrix',
        'inventor_email' => 'someone-else@pdn.ac.lk',
    ]);

    $this->actingAs($director)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('patent.pdf', 'bytes'),
    ])->assertOk();

    $documentId = $application->documents()->sole()->id;
    $stranger = User::factory()->create(['email' => 'stranger@pdn.ac.lk']);

    // [method, uri]
    $endpoints = [
        ['POST', "/applications/{$application->id}/documents"],
        ['GET', "/applications/{$application->id}/documents"],
        ['GET', '/applications/missing-documents'],
        ['GET', "/applications/{$application->id}/documents/{$documentId}/download"],
        ['DELETE', "/applications/{$application->id}/documents/{$documentId}"],
        ['GET', "/applications/{$application->id}/download"],
        ['POST', "/applications/{$application->id}/request-document"],
    ];

    foreach ($endpoints as [$method, $uri]) {
        $this->app['auth']->forgetGuards();
        $this->json($method, $uri)->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->actingAs($stranger)->json($method, $uri)->assertForbidden();
    }
});

it('lets staff drive every L4 staff endpoint', function (string $state) {
    Storage::fake('vault');
    $staff = User::factory()->{$state}()->create();
    $application = Application::query()->create(['title' => 'L4 Staff']);

    $this->actingAs($staff)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('patent.pdf', 'bytes'),
    ])->assertOk();

    $documentId = $application->documents()->sole()->id;

    $this->actingAs($staff)->getJson("/applications/{$application->id}/documents")->assertOk();
    $this->actingAs($staff)->getJson('/applications/missing-documents')->assertOk();
    $this->actingAs($staff)->get("/applications/{$application->id}/documents/{$documentId}/download")->assertOk();
    $this->actingAs($staff)->delete("/applications/{$application->id}/documents/{$documentId}")->assertNoContent();
})->with(['director', 'md']);
```

The plain-user `403` expectation for `GET /applications/{id}/download` and `POST .../request-document` holds because the stranger owns neither application.

- [ ] **Step 4: Run the matrix and the whole suite**

Run: `php artisan test --compact tests/Feature/L4RbacMatrixTest.php`
Expected: PASS (2 dataset cases + 1).

Run: `php artisan test --compact`
Expected: PASS — the L3 baseline of 298 plus the ~55 tests added here, no failures.

- [ ] **Step 5: Format**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no violations. If Pint rewrites files, re-run the tests it touched.

- [ ] **Step 6: Write ADR-0004**

Create `docs/adr/0004-documents-stored-in-the-clear.md` following the shape of `docs/adr/0001-graphify-architecture-management.md` through `0003`:

```markdown
# ADR-0004: Documents are stored in the clear

- Date: 2026-10-18
- Status: Accepted (encryption deferred)

## Context

The migration design (§7) and the old Readme promised AES-256 envelopes for
document bytes, and issue #71 specified a master-key unlock plus an RSA-4096 +
AES-256-GCM store ported from `PKIEncryptedStore`. While planning L4 the
maintainer removed encryption from this version: documents are stored as
plaintext bytes, and the vault unlock gate (`/vault/*`, 423) goes with it.

## Decision

`App\Domain\Vault\DocumentStore` is a published port with a single adapter,
`LocalDocumentStore`, writing plaintext bytes to the private `vault` disk
(`storage/app/vault`). Refs are bare UUIDs, so no caller-supplied path can
traverse. `BLITTODB_DOCUMENT_STORE` remains as the adapter seam and accepts
only `local`; `pki` fails loudly.

## Consequences

- Document confidentiality rests on the filesystem, not on cryptography: the
  database and disk hold readable patent documents.
- No `BLITTODB_VAULT_MASTER_KEY`, no key rotation, no unlock TTL, no 423.
- Adding encryption later is a second `DocumentStore` adapter plus a
  migration of existing blobs; no controller or domain flow changes.
- The old Readme's AES-256 claim is not true of this application until that
  adapter exists.
```

- [ ] **Step 7: Update the roadmap**

In `BIGGER_PICTURE.md`:

- §6 table, L4 row: replace the tests cell (`vault + upload security`) and the deliverable text so it reads:

```markdown
| L4 Document vault | [#71](https://github.com/Dushmilan/BLITTO--MANAGEMENT/issues/71) | Plaintext document store seam (`DocumentStore`/`local`), upload rules, inventor granted download (file/ZIP) + request-document, missing-documents worklist, grant-without-document warning. Encryption and the vault unlock gate are deferred (ADR-0004). | upload/download/request feature tests + journeys J1/J2/J4 |
```

- §9: replace the final `- **Next: L4 (#71)** — ...` bullet with:

```markdown
- **Done: L4 (#71, reduced scope)** — plaintext document vault: `DocumentStore`
  port + `local` adapter on a private disk, upload extension/size/filename
  guards, staff list/download/delete with filing immutability, inventor
  granted download (single file or ZIP) with `request-document`, the
  missing-documents worklist and the grant-without-document warning. No
  encryption and no vault unlock gate — deferred and recorded in ADR-0004.
- **Next: L5 (#69)** — notifications, audit trail, analytics, NIPO follow-up
  scheduler, demo seeder.
```

- Tick every `- [ ]` box in this plan file.

- [ ] **Step 8: Pin the upload limits in CI**

In `.github/workflows/test.yml`, extend the `shivammathur/setup-php@v2` step (currently `php-version`, `extensions`, `coverage`):

```yaml
        with:
          php-version: "8.3"
          extensions: sqlite3, mbstring, openssl, pdo_sqlite, curl, dom, fileinfo, tokenizer, xml
          coverage: none
          ini-values: post_max_size=12M, upload_max_filesize=12M
```

This makes CI match the documented 10 MiB contract; without it, any future test that drives a real multipart upload over 2 MB would fail in CI only.

- [ ] **Step 9: Refresh the knowledge graph**

Run (from the repository root, not `backend/`): `graphify update .`
Expected: the graph picks up `app/Domain/Vault/*` and the two new controllers. `graphify-out/` is git-ignored, so nothing is committed from it.

- [ ] **Step 10: Commit**

```bash
git add tests/Feature/DocumentJourneyTest.php tests/Feature/L4RbacMatrixTest.php docs/adr/0004-documents-stored-in-the-clear.md BIGGER_PICTURE.md .github/workflows docs/superpowers/plans/2026-10-18-l4-document-vault.md
git commit -m "docs(l4): mark document vault layer done, record the encryption deferral"
```

---

## Self-Review

**Spec coverage.** Issue #71 deliverables: master-key unlock **dropped** (scope change, ADR-0004); encrypted store **dropped** (ADR-0004); upload rules → Tasks 2, 4; download rules → Tasks 5, 6; missing-documents worklist + request-document → Tasks 5, 7. L3's deferred items: document upload/download/list → Tasks 4–6; request-document → Task 7; missing-documents worklist → Task 5; `warning` on grant → Task 8. Journeys J1/J2/J4 → Task 9. Invariants: 3 (immutability) → Task 3; 5 (audit) → Tasks 2, 3, 6, 7; 6 (confidentiality) → Tasks 6, 7 and the matrix in Task 9.

**Placeholder scan.** No `TBD`/`TODO`; every step carries its commands, code and expected result. Two steps deliberately instruct a verification-then-adjust (Task 5 Step 5 on the immutability message, Task 9 Step 2 on the J1 chain) rather than guessing a string or flow — both name exactly what to check.

**Type consistency.** `DocumentStore::put/get/delete` (Task 1) are used unchanged by `DocumentService` (Tasks 2, 3) and `bytesFor`/`tryBytesFor` (Tasks 5, 6). `DocumentRules`/`DocumentFilename` (Task 2) are used by Tasks 4–6. `GrantedDownload` (Task 6) is consumed only by `GrantedPatentController::download`. `grantWarning` (Task 8) is defined and consumed in the same task. `Application::documents()` (Task 5) is used by Task 9's matrix.

**Review Focus coverage.** Every Review Focus line names the owning task and its test: 1 → Task 4 (Step 1) + Task 9 (Step 8); 2 → Task 4 (Step 1); 3 → Tasks 1, 5 (Step 1); 4 → Tasks 3, 5 (Step 1); 5 → Task 6 (Step 1); 6 → Tasks 2, 7 (Step 1).
