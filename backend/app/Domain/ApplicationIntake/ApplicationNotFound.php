<?php

namespace App\Domain\ApplicationIntake;

use RuntimeException;

/**
 * Raised when an application id does not resolve to a row. Mapped to
 * HTTP 404 by the API layer (bootstrap/app.php).
 */
final class ApplicationNotFound extends RuntimeException
{
    public function __construct(public readonly string $applicationId)
    {
        parent::__construct("Application {$applicationId} not found");
    }
}
