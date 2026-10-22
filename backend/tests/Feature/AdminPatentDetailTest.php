<?php

use App\Models\Application;
use App\Models\User;

it('renders the detail page with the status history', function () {
    $application = Application::query()->create(['title' => 'Solar Membrane', 'status' => 'DRAFT']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)->post("/applications/{$application->id}/status", [
        'new_status' => 'FILED',
        'reason' => 'Filed with NIPO on Monday',
    ]);

    $this->app['auth']->forgetGuards();

    $this->actingAs($director)
        ->get("/admin/patents/{$application->id}")
        ->assertOk()
        ->assertSee('Solar Membrane')
        ->assertSee('Filed with NIPO on Monday')
        ->assertSeeInOrder(['DRAFT', 'FILED']);
});

it('changes the status from the form and flashes', function () {
    $application = Application::query()->create(['title' => 'Solar Membrane', 'status' => 'DRAFT']);

    $this->actingAs(User::factory()->director()->create())
        ->from("/admin/patents/{$application->id}")
        ->post("/applications/{$application->id}/status", ['new_status' => 'FILED', 'reason' => 'Sent to NIPO'])
        ->assertRedirect("/admin/patents/{$application->id}")
        ->assertSessionHas('status', 'Status updated to FILED.');

    expect($application->fresh()->status)->toBe('FILED');
});

it('edits the technology area', function () {
    $application = Application::query()->create(['title' => 'Solar Membrane', 'technology_area' => 'Old']);

    $this->actingAs(User::factory()->director()->create())
        ->from("/admin/patents/{$application->id}")
        ->patch("/applications/{$application->id}", ['technology_area' => 'Materials'])
        ->assertRedirect("/admin/patents/{$application->id}")
        ->assertSessionHas('status');

    expect($application->fresh()->technology_area)->toBe('Materials');
});

it('keeps the JSON status contract', function () {
    $application = Application::query()->create(['title' => 'Solar Membrane', 'status' => 'DRAFT']);

    $this->actingAs(User::factory()->director()->create())
        ->postJson("/applications/{$application->id}/status", ['new_status' => 'FILED', 'reason' => 'Sent'])
        ->assertNoContent();
});

it('sends an illegal transition back to the page as a form error', function () {
    $application = Application::query()->create(['title' => 'Solar Membrane', 'status' => 'DRAFT']);

    $this->actingAs(User::factory()->director()->create())
        ->from("/admin/patents/{$application->id}")
        ->post("/applications/{$application->id}/status", ['new_status' => 'GRANTED', 'reason' => 'Jump'])
        ->assertRedirect("/admin/patents/{$application->id}")
        ->assertSessionHasErrors('form');

    expect($application->fresh()->status)->toBe('DRAFT');
});

it('returns a 404 page for an unknown patent', function () {
    $this->actingAs(User::factory()->director()->create())
        ->get('/admin/patents/does-not-exist')
        ->assertNotFound();
});
