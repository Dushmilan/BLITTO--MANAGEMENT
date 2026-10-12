<?php

namespace App\Domain\Authorization;

use InvalidArgumentException;

/**
 * Creates the first MD from BLITTODB_BOOTSTRAP_ADMIN_* so the system is usable
 * out of the box. Idempotent; refuses institution-foreign mail and, outside
 * local, placeholder passwords (parity with the FastAPI startup bootstrap).
 */
final class BootstrapAdmin
{
    private const array PLACEHOLDER_PASSWORDS = [
        'password',
        'changeme',
        'change-me-in-production-32byte-minimum!',
    ];

    public function __construct(private readonly UserDirectory $directory) {}

    public function ensure(): void
    {
        $email = (string) config('blitto.bootstrap_admin_email', '');
        $password = (string) config('blitto.bootstrap_admin_password', '');

        if ($email === '' || $password === '') {
            return;
        }

        if (strtolower((string) config('blitto.environment', 'local')) !== 'local'
            && in_array($password, self::PLACEHOLDER_PASSWORDS, true)) {
            throw new InvalidArgumentException('Bootstrap admin password must not be a placeholder outside local.');
        }

        if ($this->directory->findByEmail($email) !== null) {
            return;
        }

        $this->directory->register($email, Role::MD, $password);
    }
}
