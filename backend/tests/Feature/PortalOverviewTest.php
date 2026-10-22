<?php

use App\Models\Application;
use App\Models\User;

it('counts only the viewer’s own applications', function () {
    $inventor = User::factory()->create(['email' => 'mine@pdn.ac.lk']);
    Application::query()->create(['title' => 'Mine', 'inventor_email' => 'mine@pdn.ac.lk']);
    Application::query()->create(['title' => 'Theirs', 'inventor_email' => 'theirs@pdn.ac.lk']);

    $this->actingAs($inventor)
        ->get('/user')
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

it('shows the inventor nav', function () {
    $this->actingAs(User::factory()->create())
        ->get('/user')
        ->assertOk()
        ->assertSeeInOrder(['Overview', 'Sign out'])
        ->assertDontSee('My Patents');
});

// Tasks 12 and 15 extend this assertion as My Patents and Notifications become
// real pages (each route must exist before the nav points at it).

it('refuses staff a portal page', function () {
    $this->actingAs(User::factory()->md()->create())->get('/user')->assertForbidden();
});
