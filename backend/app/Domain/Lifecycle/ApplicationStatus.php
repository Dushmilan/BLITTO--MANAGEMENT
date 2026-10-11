<?php

namespace App\Domain\Lifecycle;

/**
 * Patent application lifecycle states (CONTEXT.md §Domain Glossary).
 *
 * Values match the `applications.status` column defined in the L0 schema.
 */
enum ApplicationStatus: string
{
    case DRAFT = 'DRAFT';
    case FILED = 'FILED';
    case PUBLISHED = 'PUBLISHED';
    case EXAMINATION = 'EXAMINATION';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case DEFECT_SHEET_1 = 'DEFECT_SHEET_1';
    case DEFECT_SHEET_2 = 'DEFECT_SHEET_2';
    case DEFECT_SHEET_3 = 'DEFECT_SHEET_3';
    case GRANTED = 'GRANTED';
    case REJECTED = 'REJECTED';
    case MAINTENANCE = 'MAINTENANCE';
}
