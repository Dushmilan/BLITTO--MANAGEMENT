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
