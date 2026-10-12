<?php

use App\Models\User;

it('invalidates the session on logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/auth/logout')->assertNoContent();

    $this->assertGuest();
});

it('requires authentication to log out', function () {
    $this->postJson('/auth/logout')->assertUnauthorized();
});
