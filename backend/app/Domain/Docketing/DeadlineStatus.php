<?php

namespace App\Domain\Docketing;

/**
 * Deadline lifecycle states (CONTEXT.md invariant 1: deadline integrity).
 *
 * There is intentionally no grace/soft state — a missed deadline means the
 * application is abandoned. Values match the `deadlines.status` column.
 */
enum DeadlineStatus: string
{
    case OPEN = 'OPEN';
    case MET = 'MET';
    case MISSED = 'MISSED';
}
