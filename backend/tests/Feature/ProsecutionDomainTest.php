<?php

use App\Domain\Prosecution\OfficeActionKind;
use App\Domain\Prosecution\ProsecutionService;
use App\Domain\Prosecution\UnknownOfficeAction;
use App\Models\Application;
use App\Models\Deadline;
use App\Models\Response;
use App\Models\User;
use Illuminate\Support\Carbon;

it('dockets one response deadline 90 days out for a rejection', function () {
    $this->travelTo(Carbon::parse('2026-01-01T12:00:00Z'));

    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Prosecuted']);

    $action = app(ProsecutionService::class)->receiveOfficeAction(
        $application,
        OfficeActionKind::REJECTION,
        'Prior art cited',
        $director,
    );

    expect($action->kind)->toBe('REJECTION')
        ->and($action->body)->toBe('Prior art cited')
        ->and($action->application_id)->toBe($application->id)
        ->and($action->received_at)->not->toBeNull();

    $deadline = Deadline::query()->sole();

    expect($deadline->type)->toBe('RESPONSE')
        ->and($deadline->status)->toBe('OPEN')
        ->and($deadline->application_id)->toBe($application->id)
        ->and($deadline->due_date->toIso8601String())->toBe(Carbon::parse('2026-04-01T12:00:00Z')->toIso8601String());
});

it('does not docket a deadline for an allowance', function () {
    $application = Application::query()->create(['title' => 'Allowed']);

    app(ProsecutionService::class)->receiveOfficeAction(
        $application,
        OfficeActionKind::ALLOWANCE,
        'Allowed as filed',
        User::factory()->director()->create(),
    );

    $this->assertDatabaseCount('deadlines', 0);
    $this->assertDatabaseCount('office_actions', 1);
});

it('dockets a response deadline for an objection and audits both changes', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Objected']);

    app(ProsecutionService::class)->receiveOfficeAction($application, OfficeActionKind::OBJECTION, 'Clarity', $director);

    $this->assertDatabaseHas('audit_logs', ['action' => 'receive_office_action:OBJECTION', 'user_id' => $director->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'add_deadline:RESPONSE', 'user_id' => $director->id]);
});

it('files a response against an office action of the same application', function () {
    $application = Application::query()->create(['title' => 'Answered']);
    $service = app(ProsecutionService::class);
    $action = $service->receiveOfficeAction(
        $application,
        OfficeActionKind::REJECTION,
        'Fix claim 1',
        User::factory()->director()->create(),
    );

    $response = $service->fileResponse($application, $action, 'Amended claim 1');

    expect($response->office_action_id)->toBe($action->id)
        ->and($response->application_id)->toBe($application->id)
        ->and($response->body)->toBe('Amended claim 1')
        ->and(Response::query()->count())->toBe(1);
});

it('refuses a response filed against another application office action', function () {
    $service = app(ProsecutionService::class);
    $actor = User::factory()->director()->create();
    $owner = Application::query()->create(['title' => 'Owner']);
    $other = Application::query()->create(['title' => 'Other']);
    $action = $service->receiveOfficeAction($owner, OfficeActionKind::REJECTION, 'Fix claim 1', $actor);

    $service->fileResponse($other, $action, 'Wrong application');
})->throws(UnknownOfficeAction::class);
