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
        ->assertSeeInOrder(['Overview', 'My Patents', 'Sign out']);
});

// Task 15 extends this assertion with Notifications once that page exists.

it('refuses staff a portal page', function () {
    $this->actingAs(User::factory()->md()->create())->get('/user')->assertForbidden();
});
