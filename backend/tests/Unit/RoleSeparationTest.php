<?php

use App\Domain\Authorization\Ability;
use App\Domain\Authorization\Role;

it('separates role duties — no role can act for another', function () {
    // [role, ability, allowed] — CONTEXT.md §User Roles (RBAC) + invariant 2.
    $matrix = [
        // Users disclose: submit disclosures, view own, download granted.
        [Role::USER, Ability::DISCLOSE, true],
        [Role::USER, Ability::VIEW_OWN_APPLICATIONS, true],
        [Role::USER, Ability::DOWNLOAD_GRANTED_PATENT, true],
        // Users do not prosecute, docket, or manage users.
        [Role::USER, Ability::DOCKET, false],
        [Role::USER, Ability::PROSECUTE, false],
        [Role::USER, Ability::MANAGE_USERS, false],
        // Directors prosecute + docket, but never manage users (MD-only).
        [Role::DIRECTOR, Ability::DOCKET, true],
        [Role::DIRECTOR, Ability::PROSECUTE, true],
        [Role::DIRECTOR, Ability::MANAGE_USERS, false],
        // MDs share staff powers and additionally manage users.
        [Role::MD, Ability::DOCKET, true],
        [Role::MD, Ability::PROSECUTE, true],
        [Role::MD, Ability::MANAGE_USERS, true],
    ];

    foreach ($matrix as [$role, $ability, $allowed]) {
        $verdict = $allowed ? 'can' : 'cannot';

        expect($role->can($ability))
            ->toBe($allowed, "{$role->name} {$verdict} {$ability->name}");
    }
});
