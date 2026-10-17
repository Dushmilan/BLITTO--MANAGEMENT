<?php

use App\Domain\Filing\AcknowledgementWithoutFiling;
use App\Domain\Filing\FilingWorkflowService;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\IllegalTransition;
use App\Domain\Lifecycle\LifecycleService;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\FilingRecord;
use App\Models\Inventor;
use App\Models\Notification;
use App\Models\User;

function filingReadyApplication(array $emails = ['ada@pdn.ac.lk']): Application
{
    $application = Application::query()->create(['title' => 'Journey Widget', 'inventor_email' => $emails[0]]);

    foreach ($emails as $email) {
        Inventor::query()->create([
            'application_id' => $application->id,
            'inventor_name' => 'Inventor',
            'inventor_email' => $email,
        ]);
    }

    return $application;
}

it('marks an application filed, records the filing and notifies every inventor', function () {
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    $application = filingReadyApplication(['ada@pdn.ac.lk', 'bob@pdn.ac.lk']);

    app(FilingWorkflowService::class)->markFiled($application, $director);

    $record = FilingRecord::query()->sole();
    $reference = substr($application->id, 0, 8);

    expect($application->refresh()->status)->toBe('FILED')
        ->and($record->application_id)->toBe($application->id)
        ->and($record->filed_by)->toBe('director@pdn.ac.lk')
        ->and($record->filed_date)->not->toBeNull()
        ->and(Notification::query()->count())->toBe(2)
        ->and(Notification::query()->first()->subject)->toBe('Patent Filed')
        ->and(Notification::query()->first()->body)
        ->toBe("Your patent application Journey Widget ({$reference}) has been filed with NIPO.");

    $this->assertDatabaseHas('audit_logs', ['action' => 'filing:mark_filed', 'user_id' => $director->id]);
    $this->assertDatabaseHas('status_history', ['application_id' => $application->id, 'new_status' => 'FILED']);
});

it('ignores a repeated filing instead of rewriting the record', function () {
    $director = User::factory()->director()->create();
    $application = filingReadyApplication();
    $service = app(FilingWorkflowService::class);

    $service->markFiled($application, $director);
    $filedDate = FilingRecord::query()->sole()->filed_date;
    Notification::query()->delete();
    AuditLog::query()->delete();

    $service->markFiled($application, $director);

    expect(FilingRecord::query()->count())->toBe(1)
        ->and(FilingRecord::query()->sole()->filed_date)->toBe($filedDate)
        ->and(Notification::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'filing:mark_filed')->count())->toBe(0);
});

it('ignores a repeated acknowledgement', function () {
    $md = User::factory()->md()->create();
    $application = filingReadyApplication();
    $service = app(FilingWorkflowService::class);

    $service->markFiled($application, $md);
    $service->acknowledgeNipo($application->refresh(), $md);
    $acknowledgedAt = FilingRecord::query()->sole()->nipo_acknowledged_at;
    Notification::query()->delete();
    AuditLog::query()->delete();

    $service->acknowledgeNipo($application->refresh(), $md);

    expect(FilingRecord::query()->sole()->nipo_acknowledged_at)->toBe($acknowledgedAt)
        ->and(Notification::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'filing:acknowledge_nipo')->count())->toBe(0);
});

it('refuses to file an application whose status cannot reach FILED', function () {
    $director = User::factory()->director()->create();
    $application = filingReadyApplication();
    $application->status = ApplicationStatus::GRANTED->value;
    $application->save();

    app(FilingWorkflowService::class)->markFiled($application, $director);
})->throws(IllegalTransition::class);

it('records the NIPO acknowledgement on the filing record', function () {
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = filingReadyApplication();
    $service = app(FilingWorkflowService::class);
    $service->markFiled($application, $md);
    Notification::query()->delete();

    $service->acknowledgeNipo($application, $md);

    $record = FilingRecord::query()->sole();
    $reference = substr($application->id, 0, 8);

    expect($application->refresh()->status)->toBe('ACKNOWLEDGED')
        ->and($record->nipo_acknowledged_by)->toBe('md@pdn.ac.lk')
        ->and($record->nipo_acknowledged_at)->not->toBeNull()
        ->and(Notification::query()->sole()->subject)->toBe('Acknowledged')
        ->and(Notification::query()->sole()->body)
        ->toBe("NIPO has acknowledged receipt of your patent application Journey Widget ({$reference}).");

    $this->assertDatabaseHas('audit_logs', ['action' => 'filing:acknowledge_nipo']);
});

it('refuses to acknowledge an application that was never filed', function () {
    $application = filingReadyApplication();

    app(FilingWorkflowService::class)->acknowledgeNipo($application, User::factory()->md()->create());
})->throws(AcknowledgementWithoutFiling::class);

it('returns the filing record or null', function () {
    $application = filingReadyApplication();
    $service = app(FilingWorkflowService::class);

    expect($service->filingRecord($application))->toBeNull();

    $service->markFiled($application, User::factory()->director()->create());

    expect($service->filingRecord($application))->not->toBeNull();
});

it('leaves the lifecycle service pure', function () {
    $application = filingReadyApplication();

    (new LifecycleService)->changeStatus($application, ApplicationStatus::FILED, changedBy: 'md@pdn.ac.lk', reason: 'Manual');

    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});
