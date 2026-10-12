<?php

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;

it('returns 422 when an illegal status transition is requested over HTTP', function () {
    $application = Application::query()->create(['title' => 'Illegal jump']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'GRANTED',
        'reason' => 'Attempted grant',
    ])->assertStatus(422);
});

it('requires an authenticated staff member', function () {
    $application = Application::query()->create(['title' => 'Gated']);

    $this->postJson("/applications/{$application->id}/status", [
        'new_status' => 'FILED', 'reason' => 'ok',
    ])->assertUnauthorized();

    $this->actingAs(User::factory()->create())
        ->postJson("/applications/{$application->id}/status", [
            'new_status' => 'FILED', 'reason' => 'ok',
        ])->assertForbidden();
});

it('rejects an empty reason with 422', function () {
    $application = Application::query()->create(['title' => 'Needs a reason']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'FILED', 'reason' => '',
    ])->assertStatus(422);
});

it('rejects a whitespace-only reason with 422', function () {
    $application = Application::query()->create(['title' => 'Needs a real reason']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'FILED', 'reason' => '   ',
    ])->assertStatus(422);
});

it('rejects whitespace-only reasons in the controller itself, not just request trimming', function () {
    // TrimStrings strips ASCII whitespace before validation and `required` also
    // trims, so a plain '   ' is already 422. Disable trimming and use a form
    // feed, which PHP trim keeps but `\s` matches, to exercise the rule itself.
    TrimStrings::skipWhen(fn () => true);

    try {
        $application = Application::query()->create(['title' => 'Needs a real reason']);
        $director = User::factory()->director()->create();

        $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
            'new_status' => 'FILED', 'reason' => "\f",
        ])->assertStatus(422);
    } finally {
        TrimStrings::flushState();
    }
});

it('records the transition with the actor taken from the session', function () {
    $application = Application::query()->create(['title' => 'Filed']);
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'FILED', 'reason' => 'Filed receipt recorded',
    ])->assertNoContent();

    $this->assertDatabaseHas('status_history', [
        'application_id' => $application->id,
        'new_status' => 'FILED',
        'changed_by' => 'director@pdn.ac.lk',
        'reason' => 'Filed receipt recorded',
    ]);
});
