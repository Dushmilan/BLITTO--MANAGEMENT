<?php

namespace App\Domain\Authorization;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Confidentiality rule (CONTEXT.md invariant 6): users see only their own
 * applications; directors and MDs share the full docket and portfolio.
 *
 * Ownership is established by inventor email matching the viewer's email.
 */
class ApplicationVisibility
{
    public static function visibleTo(Builder $applications, User $viewer): Builder
    {
        $role = Role::from($viewer->role);

        if ($role === Role::USER) {
            $applications->where('inventor_email', $viewer->email);
        }

        return $applications;
    }
}
