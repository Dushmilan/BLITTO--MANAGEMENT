<?php

namespace App\Domain\Prosecution;

use RuntimeException;

/**
 * Raised when a response is filed against an office action belonging to a
 * different application.
 */
final class UnknownOfficeAction extends RuntimeException
{
    public function __construct(public readonly string $officeActionId)
    {
        parent::__construct("unknown office_action_id: {$officeActionId}");
    }
}
