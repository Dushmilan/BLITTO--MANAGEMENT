<?php

namespace App\Domain\Prosecution;

/**
 * Office-action categories (CONTEXT.md §Domain Glossary). Values match the
 * `office_actions.kind` column defined in the L0 schema.
 */
enum OfficeActionKind: string
{
    case REJECTION = 'REJECTION';
    case OBJECTION = 'OBJECTION';
    case ALLOWANCE = 'ALLOWANCE';
}
