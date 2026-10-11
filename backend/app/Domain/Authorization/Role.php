<?php

namespace App\Domain\Authorization;

/**
 * System roles (CONTEXT.md §User Roles, RBAC table).
 *
 * Values match the `users.role` column. Invariant 2 (role separation):
 * users disclose; directors and MDs prosecute and docket; only MDs manage
 * users. No role can act for another.
 */
enum Role: string
{
    case USER = 'user';
    case DIRECTOR = 'director';
    case MD = 'md';

    public function can(Ability $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    /**
     * @return list<Ability>
     */
    private function abilities(): array
    {
        return match ($this) {
            self::USER => [
                Ability::DISCLOSE,
                Ability::VIEW_OWN_APPLICATIONS,
                Ability::DOWNLOAD_GRANTED_PATENT,
            ],
            self::DIRECTOR => [
                Ability::DISCLOSE,
                Ability::VIEW_OWN_APPLICATIONS,
                Ability::DOWNLOAD_GRANTED_PATENT,
                Ability::DOCKET,
                Ability::PROSECUTE,
            ],
            // MD = director's powers + user management (shared staff docket).
            self::MD => [
                Ability::DISCLOSE,
                Ability::VIEW_OWN_APPLICATIONS,
                Ability::DOWNLOAD_GRANTED_PATENT,
                Ability::DOCKET,
                Ability::PROSECUTE,
                Ability::MANAGE_USERS,
            ],
        };
    }
}
