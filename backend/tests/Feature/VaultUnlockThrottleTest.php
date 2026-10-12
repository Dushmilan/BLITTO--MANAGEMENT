<?php

use App\Models\User;

it('throttles vault unlock attempts per IP', function () {
    $director = User::factory()->director()->create();

    foreach (range(1, 5) as $ignored) {
        $this->actingAs($director)
            ->postJson('/_test/vault-unlock', ['master_key' => 'nope'])
            ->assertUnauthorized();
    }

    $this->actingAs($director)
        ->postJson('/_test/vault-unlock', ['master_key' => 'nope'])
        ->assertStatus(429)
        ->assertHeader('Retry-After', '600');
});

it('forbids users from touching the vault probe', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/_test/vault-unlock', ['master_key' => 'nope'])
        ->assertForbidden();
});
