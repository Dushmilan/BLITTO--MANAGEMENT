<?php

use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\LifecycleService;
use App\Models\Application;
use App\Models\StatusHistory;

it('records status history with actor and timestamp when status changes', function () {
    $application = Application::query()->create(['title' => 'Audit trail']);

    (new LifecycleService)->changeStatus(
        $application,
        ApplicationStatus::FILED,
        changedBy: 'director@pdn.ac.lk',
        reason: 'Filing receipt recorded',
    );

    $history = StatusHistory::query()
        ->where('application_id', $application->id)
        ->sole();

    expect($application->refresh()->status)->toBe('FILED')
        ->and($history->old_status)->toBe('DRAFT')
        ->and($history->new_status)->toBe('FILED')
        ->and($history->changed_by)->toBe('director@pdn.ac.lk')
        ->and($history->changed_at)->not->toBeNull();
});

it('records the reason with every status change', function () {
    $application = Application::query()->create(['title' => 'Reasoned change']);

    (new LifecycleService)->changeStatus(
        $application,
        ApplicationStatus::FILED,
        changedBy: 'director@pdn.ac.lk',
        reason: 'Filing receipt received from patent office',
    );

    $history = StatusHistory::query()
        ->where('application_id', $application->id)
        ->sole();

    expect($history->reason)->toBe('Filing receipt received from patent office');
});
