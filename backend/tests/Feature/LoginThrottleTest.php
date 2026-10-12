<?php

use App\Models\User;

it('throttles repeated failed logins per account', function () {
    User::factory()->withPassword('right-pw')->create(['email' => 'victim@pdn.ac.lk']);

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/auth/login', ['email' => 'victim@pdn.ac.lk', 'password' => 'wrong']);
    }

    $this->postJson('/auth/login', ['email' => 'victim@pdn.ac.lk', 'password' => 'wrong'])
        ->assertStatus(429)
        ->assertHeader('Retry-After', '600');

    // Even the correct password is refused while throttled.
    $this->postJson('/auth/login', ['email' => 'victim@pdn.ac.lk', 'password' => 'right-pw'])
        ->assertStatus(429);
});

it('counts a case-variant of the email against the same account', function () {
    User::factory()->withPassword('right-pw')->create(['email' => 'victim@pdn.ac.lk']);

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/auth/login', ['email' => 'Victim@PDN.AC.LK', 'password' => 'wrong']);
    }

    $this->postJson('/auth/login', ['email' => 'victim@pdn.ac.lk', 'password' => 'right-pw'])
        ->assertStatus(429);
});

it('does not throttle a different account', function () {
    User::factory()->withPassword('right-pw')->create(['email' => 'victim@pdn.ac.lk']);
    User::factory()->withPassword('right-pw')->create(['email' => 'other@pdn.ac.lk']);

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/auth/login', ['email' => 'victim@pdn.ac.lk', 'password' => 'wrong']);
    }

    $this->postJson('/auth/login', ['email' => 'other@pdn.ac.lk', 'password' => 'right-pw'])
        ->assertOk();
});

it('clears the counter after a successful login', function () {
    User::factory()->withPassword('right-pw')->create(['email' => 'victim@pdn.ac.lk']);

    foreach (range(1, 4) as $ignored) {
        $this->postJson('/auth/login', ['email' => 'victim@pdn.ac.lk', 'password' => 'wrong']);
    }

    $this->postJson('/auth/login', ['email' => 'victim@pdn.ac.lk', 'password' => 'right-pw'])->assertOk();

    // Without a clear the counter would still stand at 4 after the success
    // above: this failure reaches 5, so the correct password below would be
    // refused with 429 instead of resetting the budget again.
    $this->postJson('/auth/login', ['email' => 'victim@pdn.ac.lk', 'password' => 'wrong'])
        ->assertUnauthorized();

    $this->postJson('/auth/login', ['email' => 'victim@pdn.ac.lk', 'password' => 'right-pw'])
        ->assertOk();

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/auth/login', ['email' => 'victim@pdn.ac.lk', 'password' => 'wrong']);
    }

    $this->postJson('/auth/login', ['email' => 'victim@pdn.ac.lk', 'password' => 'wrong'])
        ->assertStatus(429);
});
