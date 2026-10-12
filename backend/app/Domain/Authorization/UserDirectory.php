<?php

namespace App\Domain\Authorization;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Single entry point for creating and looking up users. Normalizes email,
 * enforces the institution gate, rejects duplicates, and hashes passwords
 * (bcrypt refuses >72 bytes rather than truncating silently).
 */
final class UserDirectory
{
    public const int MAX_PASSWORD_BYTES = 72;

    public function register(string $email, Role $role, ?string $password = null, ?string $userCode = null): User
    {
        $email = EmailPolicy::normalize($email);

        if (! EmailPolicy::isInstitution($email)) {
            throw new InvalidInstitutionEmail(EmailPolicy::error());
        }

        if ($this->findByEmail($email) !== null) {
            throw new DuplicateEmail("email already registered: {$email}");
        }

        return User::query()->create([
            'email' => $email,
            'role' => $role->value,
            'user_code' => $userCode,
            'password_hash' => $password === null ? null : $this->hash($password),
        ]);
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', EmailPolicy::normalize($email))->first();
    }

    /**
     * @return Collection<int, User>
     */
    public function all(): Collection
    {
        return User::query()->orderBy('email')->get();
    }

    private function hash(string $password): string
    {
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw new InvalidArgumentException('password exceeds 72 bytes (bcrypt limit)');
        }

        return Hash::make($password);
    }
}
