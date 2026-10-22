<?php

namespace App\Support;

use App\Domain\Authorization\Role;
use App\Models\User;

/**
 * Where a signed-in user belongs: staff to the admin area, inventors to the
 * portal (spec §7.1).
 */
final class HomeFor
{
    public static function path(User $user): string
    {
        return Role::from($user->role) === Role::USER ? '/user' : '/admin';
    }
}
