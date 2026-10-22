<?php

namespace App\Support;

use App\Domain\Authorization\Ability;
use App\Domain\Authorization\Role;
use App\Models\User;

/**
 * Sidebar items, derived from the same ability matrix the routes enforce
 * (spec §7.1). Items are added as their pages land, so a link never points at
 * a route that does not exist yet — Task 11 adds the md-only Users item.
 */
final class Navigation
{
    /**
     * @return list<array{label: string, route: string, active: string}>
     */
    public static function for(User $user): array
    {
        $role = Role::from($user->role);

        if ($role === Role::USER) {
            return [
                ['label' => 'Overview', 'route' => 'portal.overview', 'active' => 'portal.overview'],
                ['label' => 'My Patents', 'route' => 'portal.patents.index', 'active' => 'portal.patents.*'],
            ];
        }

        $items = [
            ['label' => 'Overview', 'route' => 'admin.dashboard', 'active' => 'admin.dashboard'],
            ['label' => 'Patents', 'route' => 'admin.patents.index', 'active' => 'admin.patents.*'],
        ];

        if ($role->can(Ability::MANAGE_USERS)) {
            $items[] = ['label' => 'Users', 'route' => 'admin.users.index', 'active' => 'admin.users.*'];
        }

        return $items;
    }
}
