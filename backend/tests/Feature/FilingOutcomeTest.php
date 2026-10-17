<?php

use App\Domain\Filing\FilingWorkflowService;
use App\Domain\Lifecycle\IllegalTransition;
use App\Models\Application;
use App\Models\FilingRecord;
use App\Models\Inventor;
use App\Models\Notification;
use App\Models\User;

function acknowledgedApplication(): Application
{
    $application = Application::query()->create(['title' => 'Grant Flow', 'inventor_email' => 'ada@pdn.ac.lk']);
    Inventor::query()->create([
        'application_id' => $application->id,
        'inventor_name' => 'Ada',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $service = app(FilingWorkflowService::class);
    $service->markFiled($application, $md);
    $service->acknowledgeNipo($application->refresh(), $md);

    return $application->refresh();
}

it('grants a patent and stamps the filing record', function () {
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    $application = acknowledgedApplication();
    Notification::query()->delete();

    app(FilingWorkflowService::class)->markGranted($application, 'LK/PAT/2026/999', $director);

    $record = FilingRecord::query()->sole();

    expect($application->refresh()->status)->toBe('GRANTED')
        ->and($record->patent_number)->toBe('LK/PAT/2026/999')
        ->and($record->granted_by)->toBe('director@pdn.ac.lk')
        ->and($record->granted_at)->not->toBeNull()
        ->and(Notification::query()->sole()->subject)->toBe('Patent Received')
        ->and(Notification::query()->sole()->body)
        ->toBe('Your patent Grant Flow has been granted with patent number LK/PAT/2026/999.');

    $this->assertDatabaseHas('audit_logs', ['action' => 'filing:granted', 'user_id' => $director->id]);
});

it('grants without a filing record and still notifies', function () {
    $application = Application::query()->create(['title' => 'Direct Grant', 'inventor_email' => 'ada@pdn.ac.lk', 'status' => 'ACKNOWLEDGED']);
    Inventor::query()->create([
        'application_id' => $application->id,
        'inventor_name' => 'Ada',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);

    app(FilingWorkflowService::class)->markGranted($application, 'LK/PAT/2026/1', User::factory()->director()->create());

    expect($application->refresh()->status)->toBe('GRANTED')
        ->and(FilingRecord::query()->count())->toBe(0)
        ->and(Notification::query()->sole()->subject)->toBe('Patent Received');
});

it('refuses to grant straight from DRAFT and writes nothing', function () {
    $application = Application::query()->create(['title' => 'Jump', 'inventor_email' => 'ada@pdn.ac.lk']);

    app(FilingWorkflowService::class)->markGranted($application, 'LK/PAT/2026/2', User::factory()->director()->create());
})->throws(IllegalTransition::class);

it('rejects a filed application and stamps the filing record', function () {
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Reject Flow', 'inventor_email' => 'ada@pdn.ac.lk']);
    Inventor::query()->create([
        'application_id' => $application->id,
        'inventor_name' => 'Ada',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);
    $service = app(FilingWorkflowService::class);
    $service->markFiled($application, $director);
    Notification::query()->delete();

    $service->markRejected($application->refresh(), $director);

    $record = FilingRecord::query()->sole();
    $reference = substr($application->id, 0, 8);

    expect($application->refresh()->status)->toBe('REJECTED')
        ->and($record->rejected_by)->toBe('director@pdn.ac.lk')
        ->and($record->rejected_at)->not->toBeNull()
        ->and(Notification::query()->sole()->subject)->toBe('Rejected')
        ->and(Notification::query()->sole()->body)
        ->toBe("Your patent application Reject Flow ({$reference}) has been rejected.");

    $this->assertDatabaseHas('audit_logs', ['action' => 'filing:rejected', 'user_id' => $director->id]);
});

it('refuses to reject straight from DRAFT', function () {
    $application = Application::query()->create(['title' => 'Jump', 'inventor_email' => 'ada@pdn.ac.lk']);

    app(FilingWorkflowService::class)->markRejected($application, User::factory()->director()->create());
})->throws(IllegalTransition::class);
