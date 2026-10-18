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
