<?php

use App\Models\User;

it('requires authentication for /auth/me', function () {
    $this->getJson('/auth/me')->assertUnauthorized();
});

it('returns the authenticated user from /auth/me', function () {
    $user = User::factory()->create(['email' => 'ada@pdn.ac.lk', 'role' => 'user']);

    $this->actingAs($user)
        ->getJson('/auth/me')
        ->assertOk()
        ->assertJsonPath('email', 'ada@pdn.ac.lk')
        ->assertJsonPath('role', 'user')
        ->assertJsonMissingPath('password_hash');
});
