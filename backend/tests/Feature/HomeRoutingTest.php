<?php

use App\Models\User;

it('sends each role to its home', function () {
    $this->get('/')->assertRedirect(route('login'));
    $this->app['auth']->forgetGuards();

    $this->actingAs(User::factory()->md()->create())->get('/')->assertRedirect('/admin');
    $this->app['auth']->forgetGuards();

    $this->actingAs(User::factory()->director()->create())->get('/')->assertRedirect('/admin');
    $this->app['auth']->forgetGuards();

    $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/user');
});
