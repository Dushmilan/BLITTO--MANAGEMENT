<?php

use App\Models\User;

it('logs in an institution user and establishes a session', function () {
    $user = User::factory()->withPassword('s3cret-pw')->create(['email' => 'ada@pdn.ac.lk']);

    $this->postJson('/auth/login', ['email' => 'ada@pdn.ac.lk', 'password' => 's3cret-pw'])
        ->assertOk()
        ->assertJsonPath('email', 'ada@pdn.ac.lk');

    $this->assertAuthenticatedAs($user);
});

it('normalizes the submitted email before authenticating', function () {
    User::factory()->withPassword('s3cret-pw')->create(['email' => 'ada@pdn.ac.lk']);

    $this->postJson('/auth/login', ['email' => '  ADA@PDN.AC.LK ', 'password' => 's3cret-pw'])
        ->assertOk();
});

it('rejects a wrong password with 401', function () {
    User::factory()->withPassword('s3cret-pw')->create(['email' => 'ada@pdn.ac.lk']);

    $this->postJson('/auth/login', ['email' => 'ada@pdn.ac.lk', 'password' => 'wrong'])
        ->assertUnauthorized();
});

it('rejects an account with no password without a server error', function () {
    User::factory()->create(['email' => 'ada@pdn.ac.lk', 'password_hash' => null]);

    $this->postJson('/auth/login', ['email' => 'ada@pdn.ac.lk', 'password' => 'anything'])
        ->assertUnauthorized();
});

it('rejects a non-institution email with 403', function () {
    $this->postJson('/auth/login', ['email' => 'x@gmail.com', 'password' => 'pw'])
        ->assertForbidden();
});
