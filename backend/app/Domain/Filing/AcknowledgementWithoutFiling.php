<?php

namespace App\Domain\Filing;

use RuntimeException;

/**
 * Raised when NIPO acknowledgement is recorded before the application was
 * filed. Rendered as HTTP 404 by bootstrap/app.php (reference parity).
 */
final class AcknowledgementWithoutFiling extends RuntimeException
{
    public function __construct(public readonly string $applicationId)
    {
        parent::__construct("No filing record found for {$applicationId} - mark filed first");
    }
}
