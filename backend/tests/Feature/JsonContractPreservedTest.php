<?php

use App\Models\Application;
use App\Models\User;

it('keeps the illegal-transition JSON body', function () {
    $application = Application::query()->create(['title' => 'Draft Two', 'status' => 'DRAFT']);

    $this->actingAs(User::factory()->director()->create())
        ->postJson("/applications/{$application->id}/status", ['new_status' => 'GRANTED', 'reason' => 'nope'])
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
});

it('keeps the duplicate-email JSON body', function () {
    User::factory()->create(['email' => 'taken@pdn.ac.lk']);
    $md = User::factory()->md()->create();

    $this->actingAs($md)
        ->postJson('/auth/register', ['email' => 'taken@pdn.ac.lk', 'role' => 'user'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'email already registered: taken@pdn.ac.lk');
});

it('keeps the document-too-large JSON body', function () {
    $application = Application::query()->create(['title' => 'Big Upload']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)
        ->postJson("/applications/{$application->id}/documents", [])
        ->assertStatus(422);
});
