<?php

namespace App\Domain\Authorization;

/**
 * Duties that roles may hold (CONTEXT.md §User Roles + invariant 2).
 */
enum Ability: string
{
    case DISCLOSE = 'DISCLOSE';
    case VIEW_OWN_APPLICATIONS = 'VIEW_OWN_APPLICATIONS';
    case DOWNLOAD_GRANTED_PATENT = 'DOWNLOAD_GRANTED_PATENT';
    case DOCKET = 'DOCKET';
    case PROSECUTE = 'PROSECUTE';
    case MANAGE_USERS = 'MANAGE_USERS';
}
