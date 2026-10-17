<?php

namespace App\Domain\Docketing;

/**
 * Deadline categories (CONTEXT.md §Domain Glossary). Values match the
 * `deadlines.type` column defined in the L0 schema.
 */
enum DeadlineType: string
{
    case FILING = 'FILING';
    case RESPONSE = 'RESPONSE';
    case MAINTENANCE_FEE = 'MAINTENANCE_FEE';
}
