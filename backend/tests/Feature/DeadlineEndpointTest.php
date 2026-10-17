<?php

use App\Domain\Docketing\DeadlineType;
use App\Domain\Docketing\DocketingService;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Carbon;

it('adds a deadline for an application as staff', function (string $state) {
    $staff = User::factory()->{$state}()->create();
    $application = Application::query()->create(['title' => 'Docketed']);

    $response = $this->actingAs($staff)->postJson(
        "/applications/{$application->id}/deadlines?type=RESPONSE&due_date=2027-01-01T00:00:00Z",
    );

    $response->assertOk()
        ->assertJsonPath('application_id', $application->id)
        ->assertJsonPath('type', 'RESPONSE')
        ->assertJsonPath('status', 'OPEN');

    $this->assertDatabaseHas('deadlines', [
        'application_id' => $application->id,
        'type' => 'RESPONSE',
        'status' => 'OPEN',
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'add_deadline:RESPONSE', 'user_id' => $staff->id]);
})->with(['director', 'md']);

it('validates the deadline type and due date', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Validated']);

    $this->actingAs($director)
        ->postJson("/applications/{$application->id}/deadlines?due_date=2027-01-01T00:00:00Z")
        ->assertStatus(422)->assertJsonValidationErrors('type');

    $this->actingAs($director)
        ->postJson("/applications/{$application->id}/deadlines?type=NOPE&due_date=2027-01-01T00:00:00Z")
        ->assertStatus(422)->assertJsonValidationErrors('type');

    $this->actingAs($director)
        ->postJson("/applications/{$application->id}/deadlines?type=FILING&due_date=notadate")
        ->assertStatus(422)->assertJsonValidationErrors('due_date');
});

it('returns 404 for a deadline on an unknown application', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)
        ->postJson('/applications/unknown-app/deadlines?type=FILING&due_date=2027-01-01T00:00:00Z')
        ->assertNotFound()
        ->assertJsonPath('message', 'Application unknown-app not found');
});

it('lists deadlines for staff, filtered by application', function () {
    $md = User::factory()->md()->create();
    $first = Application::query()->create(['title' => 'First']);
    $second = Application::query()->create(['title' => 'Second']);
    $deadlines = app(DocketingService::class);
    $due = Carbon::parse('2026-01-01T00:00:00Z');

    $deadlines->addDeadline($first, DeadlineType::FILING, $due, $md);
    $deadlines->addDeadline($second, DeadlineType::RESPONSE, $due, $md);

    expect($this->actingAs($md)->getJson('/deadlines')->assertOk()->json())->toHaveCount(2)
        ->and($this->actingAs($md)->getJson("/deadlines?application_id={$first->id}")->assertOk()->json())->toHaveCount(1)
        ->and($this->actingAs($md)->getJson('/deadlines?application_id=unknown-app')->assertOk()->json())->toBe([]);
});

it('gates the deadline endpoints to staff', function () {
    $application = Application::query()->create(['title' => 'Gated']);
    $uri = "/applications/{$application->id}/deadlines?type=FILING&due_date=2027-01-01T00:00:00Z";

    $this->postJson($uri)->assertUnauthorized();
    $this->getJson('/deadlines')->assertUnauthorized();

    $user = User::factory()->create();
    $this->actingAs($user)->postJson($uri)->assertForbidden();
    $this->actingAs($user)->getJson('/deadlines')->assertForbidden();
});
