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

        // The disk is configured with throw:false, so a refused write returns
        // false rather than raising; a row without bytes is worse than a 500.
        if (! $this->disk->put($ref, $contents)) {
            throw new DocumentStoreFailure('The vault disk refused the write.');
        }

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
