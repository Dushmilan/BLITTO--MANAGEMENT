<?php

use App\Models\User;

it('signs in through the form and lands on the staff home', function () {
    User::factory()->md()->withPassword('s3cret-pw')->create(['email' => 'md@pdn.ac.lk']);

    $this->post('/auth/login', ['email' => 'md@pdn.ac.lk', 'password' => 's3cret-pw'])
        ->assertRedirect('/admin');

    $this->assertAuthenticated();
});

it('comes back with an error and no password when the credentials are wrong', function () {
    User::factory()->withPassword('s3cret-pw')->create(['email' => 'ada@pdn.ac.lk']);

    $this->from('/login')
        ->post('/auth/login', ['email' => 'ada@pdn.ac.lk', 'password' => 'wrong'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email')
        ->assertSessionMissing('_old_input.password');

    $this->assertGuest();
});

it('rejects a non-institution email with the reference message', function () {
    $this->from('/login')
        ->post('/auth/login', ['email' => 'someone@gmail.com', 'password' => 'pw'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'Enter a valid institution email address.']);
});

it('keeps the JSON login contract untouched', function () {
    User::factory()->withPassword('s3cret-pw')->create(['email' => 'ada@pdn.ac.lk']);

    $this->postJson('/auth/login', ['email' => 'ada@pdn.ac.lk', 'password' => 's3cret-pw'])
        ->assertOk()
        ->assertJsonPath('email', 'ada@pdn.ac.lk');

    $this->postJson('/auth/login', ['email' => 'ada@pdn.ac.lk', 'password' => 'wrong'])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Invalid credentials');
});

it('logs out through the form and returns to the login page', function () {
    $this->actingAs(User::factory()->create())
        ->post('/auth/logout')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
