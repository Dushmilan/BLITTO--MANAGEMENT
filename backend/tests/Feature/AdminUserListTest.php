<?php

use App\Models\User;

it('lists users for an MD and filters by search text', function () {
    User::factory()->create(['email' => 'ada@pdn.ac.lk']);
    User::factory()->create(['email' => 'grace@pdn.ac.lk']);

    $md = User::factory()->md()->create();

    $this->actingAs($md)->get('/admin/users')
        ->assertOk()
        ->assertSee('ada@pdn.ac.lk')
        ->assertSee('grace@pdn.ac.lk')
        ->assertSee('users registered');

    $this->app['auth']->forgetGuards();

    $this->actingAs($md)->get('/admin/users?q=grace')
        ->assertOk()
        ->assertSee('grace@pdn.ac.lk')
        ->assertDontSee('ada@pdn.ac.lk');
});

it('refuses a director', function () {
    $this->actingAs(User::factory()->director()->create())->get('/admin/users')->assertForbidden();
});

it('shows the Users nav item to an MD only', function () {
    $this->actingAs(User::factory()->md()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSeeInOrder(['Overview', 'Patents', 'Users']);

    $this->app['auth']->forgetGuards();

    $this->actingAs(User::factory()->director()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSeeInOrder(['Overview', 'Patents']);
});

it('creates a user from the form and flashes', function () {
    $this->actingAs(User::factory()->md()->create())
        ->from('/admin/users')
        ->post('/auth/register', [
            'email' => 'newdir@pdn.ac.lk',
            'role' => 'director',
            'password' => 's3cret-pw',
        ])
        ->assertRedirect('/admin/users')
        ->assertSessionHas('status', 'User created.');

    expect(User::query()->where('email', 'newdir@pdn.ac.lk')->exists())->toBeTrue();
});

it('returns domain failures from the form as field errors', function () {
    User::factory()->create(['email' => 'taken@pdn.ac.lk']);

    $this->actingAs(User::factory()->md()->create())
        ->from('/admin/users')
        ->post('/auth/register', ['email' => 'taken@pdn.ac.lk', 'role' => 'user'])
        ->assertRedirect('/admin/users')
        ->assertSessionHasErrors('form');
});

it('keeps the JSON register contract', function () {
    $this->actingAs(User::factory()->md()->create())
        ->postJson('/auth/register', ['email' => 'json@pdn.ac.lk', 'role' => 'user', 'password' => 's3cret-pw'])
        ->assertOk()
        ->assertJsonPath('email', 'json@pdn.ac.lk');
});
