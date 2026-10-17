<?php

use App\Models\Application;
use App\Models\User;

it('receives an office action and dockets the response deadline', function (string $state) {
    $staff = User::factory()->{$state}()->create();
    $application = Application::query()->create(['title' => 'Prosecuted']);

    $response = $this->actingAs($staff)->postJson(
        "/applications/{$application->id}/office-actions?kind=REJECTION&body=Prior%20art%20cited",
    );

    $response->assertOk()
        ->assertJsonPath('kind', 'REJECTION')
        ->assertJsonPath('body', 'Prior art cited')
        ->assertJsonPath('application_id', $application->id);

    $this->assertDatabaseHas('office_actions', ['application_id' => $application->id, 'kind' => 'REJECTION']);
    $this->assertDatabaseHas('deadlines', ['application_id' => $application->id, 'type' => 'RESPONSE', 'status' => 'OPEN']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'receive_office_action:REJECTION', 'user_id' => $staff->id]);
})->with(['director', 'md']);

it('does not docket a deadline for an allowance', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Allowed']);

    $this->actingAs($director)->postJson(
        "/applications/{$application->id}/office-actions?kind=ALLOWANCE&body=Allowed%20as%20filed",
    )->assertOk();

    $this->assertDatabaseCount('deadlines', 0);
});

it('validates the kind and body', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Validated']);

    $this->actingAs($director)
        ->postJson("/applications/{$application->id}/office-actions?body=no%20kind")
        ->assertStatus(422)->assertJsonValidationErrors('kind');

    $this->actingAs($director)
        ->postJson("/applications/{$application->id}/office-actions?kind=SHRUG&body=x")
        ->assertStatus(422)->assertJsonValidationErrors('kind');

    $this->actingAs($director)
        ->postJson("/applications/{$application->id}/office-actions?kind=REJECTION")
        ->assertStatus(422)->assertJsonValidationErrors('body');
});

it('returns 404 for an office action on an unknown application', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)
        ->postJson('/applications/unknown-app/office-actions?kind=REJECTION&body=x')
        ->assertNotFound()
        ->assertJsonPath('message', 'Application unknown-app not found');
});

it('gates office actions to staff', function () {
    $application = Application::query()->create(['title' => 'Gated']);
    $uri = "/applications/{$application->id}/office-actions?kind=REJECTION&body=x";

    $this->postJson($uri)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($uri)->assertForbidden();
});
