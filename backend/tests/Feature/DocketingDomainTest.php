<?php

use App\Domain\Docketing\DeadlineType;
use App\Domain\Docketing\DocketingService;
use App\Models\Application;
use App\Models\Deadline;
use App\Models\User;
use Illuminate\Support\Carbon;

it('adds an open deadline and audits it', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Docketed']);
    $due = Carbon::parse('2026-12-31T00:00:00Z');

    $deadline = app(DocketingService::class)->addDeadline($application, DeadlineType::FILING, $due, $director);

    expect($deadline->status)->toBe('OPEN')
        ->and($deadline->type)->toBe('FILING')
        ->and($deadline->application_id)->toBe($application->id)
        ->and($deadline->due_date->toIso8601String())->toBe($due->toIso8601String());

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'add_deadline:FILING',
        'user_id' => $director->id,
        'application_id' => $application->id,
    ]);
});

it('accepts every deadline type', function (DeadlineType $type) {
    $application = Application::query()->create(['title' => 'Typed']);

    $deadline = app(DocketingService::class)->addDeadline(
        $application,
        $type,
        Carbon::parse('2026-06-01T00:00:00Z'),
        User::factory()->director()->create(),
    );

    expect($deadline->refresh()->type)->toBe($type->value);
})->with(DeadlineType::cases());

it('lists all deadlines or only one application deadlines', function () {
    $first = Application::query()->create(['title' => 'First']);
    $second = Application::query()->create(['title' => 'Second']);
    $service = app(DocketingService::class);
    $actor = User::factory()->director()->create();
    $due = Carbon::parse('2026-01-01T00:00:00Z');

    $service->addDeadline($first, DeadlineType::FILING, $due, $actor);
    $service->addDeadline($second, DeadlineType::RESPONSE, $due, $actor);

    expect($service->listDeadlines())->toHaveCount(2)
        ->and($service->listDeadlines($first->id))->toHaveCount(1)
        ->and($service->listDeadlines($first->id)->first()->application_id)->toBe($first->id)
        ->and($service->listDeadlines('missing-id'))->toBeEmpty();
});

it('allows several deadlines of the same type on one application', function () {
    $application = Application::query()->create(['title' => 'Many']);
    $service = app(DocketingService::class);
    $actor = User::factory()->director()->create();

    $service->addDeadline($application, DeadlineType::RESPONSE, Carbon::parse('2026-06-01T00:00:00Z'), $actor);
    $service->addDeadline($application, DeadlineType::RESPONSE, Carbon::parse('2026-07-01T00:00:00Z'), $actor);

    expect(Deadline::query()->where('application_id', $application->id)->count())->toBe(2);
});
