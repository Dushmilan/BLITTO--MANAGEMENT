<?php

use App\Domain\Docketing\DocketingService;
use App\Domain\Docketing\IllegalDeadlineChange;
use App\Models\Application;
use App\Models\Deadline;

it('rejects softening a missed deadline back to met', function () {
    $application = Application::query()->create(['title' => 'Deadline integrity']);
    $deadline = Deadline::query()->create([
        'application_id' => $application->id,
        'type' => 'RESPONSE',
        'due_date' => '2026-10-01 00:00:00',
        'status' => 'MISSED',
    ]);

    (new DocketingService)->markMet($deadline);
})->throws(IllegalDeadlineChange::class);

it('marks an open deadline as met', function () {
    $application = Application::query()->create(['title' => 'Deadline met']);
    $deadline = Deadline::query()->create([
        'application_id' => $application->id,
        'type' => 'RESPONSE',
        'due_date' => '2027-01-01 00:00:00',
        'status' => 'OPEN',
    ]);

    $result = (new DocketingService)->markMet($deadline);

    expect($result->status)->toBe('MET')
        ->and($deadline->refresh()->status)->toBe('MET');
});
