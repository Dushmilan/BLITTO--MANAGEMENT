<?php

use App\Models\Application;
use App\Models\User;

it('shows the portfolio summary to a director', function () {
    Application::query()->create(['title' => 'Filed One', 'status' => 'FILED', 'inventor_email' => 'a@pdn.ac.lk']);
    Application::query()->create(['title' => 'Granted One', 'status' => 'GRANTED', 'inventor_email' => 'b@pdn.ac.lk']);

    $this->actingAs(User::factory()->director()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Total patents')
        ->assertSee('Filed One');
});

it('lists granted patents that have no document as missing', function () {
    Application::query()->create(['title' => 'Waiting On Us', 'status' => 'GRANTED']);

    $this->actingAs(User::factory()->md()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Missing documents')
        ->assertSee('Waiting On Us');
});

it('shows the user cards to an MD only', function () {
    $this->actingAs(User::factory()->md()->create())->get('/admin')->assertOk()->assertSee('Users');

    $this->app['auth']->forgetGuards();

    // "Inventors" is one of the md-only cards.
    $this->actingAs(User::factory()->director()->create())->get('/admin')->assertOk()->assertDontSee('Inventors');
});

it('refuses a plain user', function () {
    // The guest variant (redirect to login instead of JSON 401) needs Task 4's
    // renderer negotiation; it is asserted in HtmlAccessTest.
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

it('shows the staff nav to a director and never the inventory nav', function () {
    $this->actingAs(User::factory()->director()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSeeInOrder(['Overview', 'Sign out'])
        ->assertDontSee('My Patents');
});
