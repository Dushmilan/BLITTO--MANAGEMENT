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
