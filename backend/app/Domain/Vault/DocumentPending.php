<?php

namespace App\Domain\Vault;

use RuntimeException;

/** GRANTED with no documents: 409 with a machine-readable code. */
final class DocumentPending extends RuntimeException
{
    public static function forApplication(): self
    {
        return new self(
            'Patent is GRANTED but the document has not been uploaded yet. '
            .'You can request it via POST /applications/{application_id}/request-document.'
        );
    }
}
