<?php

use App\Models\Application;
use App\Models\User;

it('lists only the viewer’s patents', function () {
    $inventor = User::factory()->create(['email' => 'mine@pdn.ac.lk']);
    Application::query()->create(['title' => 'Mine Patent', 'inventor_email' => 'mine@pdn.ac.lk', 'technology_area' => 'Pharma']);
    Application::query()->create(['title' => 'Theirs Patent', 'inventor_email' => 'theirs@pdn.ac.lk']);

    $this->actingAs($inventor)
        ->get('/user/patents')
        ->assertOk()
        ->assertSee('Mine Patent')
        ->assertSee('Pharma')
        ->assertDontSee('Theirs Patent');
});

it('shows the portal nav with My Patents', function () {
    $this->actingAs(User::factory()->create())
        ->get('/user/patents')
        ->assertOk()
        ->assertSeeInOrder(['Overview', 'My Patents', 'Sign out']);
});

it('shows the empty state', function () {
    $this->actingAs(User::factory()->create())
        ->get('/user/patents')
        ->assertOk()
        ->assertSee('Your patents will appear here once submitted.');
});

it('keeps staff out', function () {
    $this->actingAs(User::factory()->md()->create())->get('/user/patents')->assertForbidden();
});
