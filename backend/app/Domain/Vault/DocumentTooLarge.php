<?php

namespace App\Domain\Vault;

use RuntimeException;

/** The upload exceeds the 10 MiB cap (HTTP 413). */
final class DocumentTooLarge extends RuntimeException
{
    /**
     * The single wording for "over the cap", whether the byte count was
     * measured by this app or by PHP's own upload limit.
     */
    public static function upload(): self
    {
        return new self('Document too large (max 10 MB)');
    }
}
