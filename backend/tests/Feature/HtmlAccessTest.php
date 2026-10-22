<?php

use App\Models\User;

it('renders the styled 403 page for a wrong role', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertForbidden()
        ->assertSee('Forbidden')
        ->assertDontSee('"message"', false);
});

it('redirects a guest to login rather than answering with JSON', function () {
    $this->get('/admin')
        ->assertRedirect(route('login'))
        ->assertDontSee('Unauthenticated');
});

it('returns the intended page after signing in', function () {
    User::factory()->md()->withPassword('s3cret-pw')->create(['email' => 'md@pdn.ac.lk']);

    $this->get('/admin')->assertRedirect(route('login'));

    $this->post('/auth/login', ['email' => 'md@pdn.ac.lk', 'password' => 's3cret-pw'])
        ->assertRedirect('/admin');
});

it('keeps JSON unauthorized and forbidden responses unchanged', function () {
    $this->json('GET', '/users')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->actingAs(User::factory()->director()->create())->json('GET', '/users')->assertForbidden();
});
