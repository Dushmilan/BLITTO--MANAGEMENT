<?php

namespace Database\Factories;

use App\Domain\Authorization\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->userName().'@pdn.ac.lk',
            'role' => Role::USER->value,
            'user_code' => null,
            'password_hash' => Hash::make('password'),
        ];
    }

    public function user(): static
    {
        return $this->state(fn (): array => ['role' => Role::USER->value]);
    }

    public function md(): static
    {
        return $this->state(fn (): array => ['role' => Role::MD->value]);
    }

    public function director(): static
    {
        return $this->state(fn (): array => ['role' => Role::DIRECTOR->value]);
    }

    public function withPassword(string $password): static
    {
        return $this->state(fn (): array => ['password_hash' => Hash::make($password)]);
    }
}
