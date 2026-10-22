<?php

use App\Models\User;

it('renders the login page for a guest', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Welcome back')
        ->assertSee('Sign in to access your docket')
        ->assertSee('University of Peradeniya');
});

it('renders the login form posting to the existing auth route', function () {
    $response = $this->get('/login')->assertOk();

    $response->assertSee('name="email"', false);
    $response->assertSee('name="password"', false);
    $response->assertSee('action="'.route('auth.login').'"', false);
    $response->assertSee('name="_token"', false);
});

it('redirects an authenticated visitor away from the login page', function () {
    $md = User::factory()->md()->create();

    $this->actingAs($md)->get('/login')->assertRedirect();
});
