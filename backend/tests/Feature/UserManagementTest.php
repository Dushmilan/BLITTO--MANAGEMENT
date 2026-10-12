<?php

use App\Models\User;

it('restricts the user list to the MD', function () {
    foreach (['user', 'director'] as $state) {
        $this->actingAs(User::factory()->{$state}()->create())
            ->getJson('/users')
            ->assertForbidden();
    }

    $this->actingAs(User::factory()->md()->create())->getJson('/users')->assertOk();
});

it('requires authentication for the user list', function () {
    $this->getJson('/users')->assertUnauthorized();
});

it('never exposes password hashes in the user list', function () {
    $md = User::factory()->md()->create();
    User::factory()->create();

    $list = $this->actingAs($md)->getJson('/users')->assertOk()->json();

    expect($list)->toBeArray()
        ->and(collect($list)->every(fn (array $user): bool => ! array_key_exists('password_hash', $user)))->toBeTrue();
});
