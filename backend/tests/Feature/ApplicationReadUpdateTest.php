<?php

use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\LifecycleService;
use App\Models\Application;
use App\Models\User;

it('shows a user only their own applications', function () {
    $owner = User::factory()->create(['email' => 'ada@pdn.ac.lk']);
    $other = User::factory()->create(['email' => 'bob@pdn.ac.lk']);
    $mine = Application::query()->create(['title' => 'Mine', 'inventor_email' => 'ada@pdn.ac.lk']);
    Application::query()->create(['title' => 'Theirs', 'inventor_email' => 'bob@pdn.ac.lk']);

    $response = $this->actingAs($owner)->getJson('/applications');

    $response->assertOk();
    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($mine->id)
        ->and($this->actingAs($other)->getJson('/applications')->json())->toHaveCount(1);
});

it('shows every application to staff', function () {
    $director = User::factory()->director()->create();
    Application::query()->create(['title' => 'A', 'inventor_email' => 'a@pdn.ac.lk']);
    Application::query()->create(['title' => 'B', 'inventor_email' => 'b@pdn.ac.lk']);

    expect($this->actingAs($director)->getJson('/applications')->assertOk()->json())->toHaveCount(2);
});

it('gates the application list to authenticated users', function () {
    $this->getJson('/applications')->assertUnauthorized();
});

it('updates the editable fields as staff', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Old', 'technology_area' => 'Old Tech']);

    $this->actingAs($director)->patchJson("/applications/{$application->id}", [
        'title' => 'Renamed',
        'technology_area' => 'Bio',
    ])->assertOk()
        ->assertJsonPath('title', 'Renamed')
        ->assertJsonPath('technology_area', 'Bio')
        ->assertJsonPath('status', 'DRAFT');

    $this->assertDatabaseHas('applications', ['id' => $application->id, 'title' => 'Renamed', 'status' => 'DRAFT']);
});

it('rejects a patch that carries no editable field', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Fixed']);

    $this->actingAs($director)->patchJson("/applications/{$application->id}", ['status' => 'granted'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'No editable fields provided');
});

it('returns 404 for a patch against an unknown application', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->patchJson('/applications/missing-id', ['title' => 'X'])
        ->assertNotFound()
        ->assertJsonPath('message', 'Application missing-id not found');
});

it('gates patching to staff', function () {
    $application = Application::query()->create(['title' => 'Gated']);
    $payload = ['title' => 'Nope'];

    $this->patchJson("/applications/{$application->id}", $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->create())
        ->patchJson("/applications/{$application->id}", $payload)->assertForbidden();
});

it('returns the status history of an application to its inventor', function () {
    $owner = User::factory()->create(['email' => 'ada@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Trail', 'inventor_email' => 'ada@pdn.ac.lk']);

    (new LifecycleService)->changeStatus($application, ApplicationStatus::FILED, changedBy: 'director@pdn.ac.lk', reason: 'Filed');

    $this->actingAs($owner)->getJson("/applications/{$application->id}/history")
        ->assertOk()
        ->assertJsonPath('0.new_status', 'FILED')
        ->assertJsonPath('0.old_status', 'DRAFT')
        ->assertJsonPath('0.changed_by', 'director@pdn.ac.lk')
        ->assertJsonPath('0.reason', 'Filed');
});

it('refuses history for another inventor application', function () {
    $other = User::factory()->create(['email' => 'bob@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Not mine', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($other)->getJson("/applications/{$application->id}/history")->assertForbidden();
});

it('lets staff read any history and returns 404 for unknown applications', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Any', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($director)->getJson("/applications/{$application->id}/history")->assertOk();
    $this->actingAs($director)->getJson('/applications/missing-id/history')->assertNotFound();
});

it('gates history to authenticated users', function () {
    $application = Application::query()->create(['title' => 'Gated']);

    $this->getJson("/applications/{$application->id}/history")->assertUnauthorized();
});
