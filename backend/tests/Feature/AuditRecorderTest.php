<?php

use App\Domain\Audit\AuditRecorder;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\User;

it('records an audit entry with actor, application and ip', function () {
    $application = Application::query()->create(['title' => 'Audited']);
    $director = User::factory()->director()->create();

    (new AuditRecorder)->record('create_application', actor: $director, application: $application, ipAddress: '10.0.0.7');

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('create_application')
        ->and($log->user_id)->toBe($director->id)
        ->and($log->application_id)->toBe($application->id)
        ->and($log->ip_address)->toBe('10.0.0.7')
        ->and($log->timestamp)->not->toBeNull();
});

it('records an audit entry with no actor or application', function () {
    (new AuditRecorder)->record('system_check');

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('system_check')
        ->and($log->user_id)->toBeNull()
        ->and($log->application_id)->toBeNull();
});

it('scopes audit entries to one application', function () {
    $first = Application::query()->create(['title' => 'First']);
    $second = Application::query()->create(['title' => 'Second']);
    $recorder = new AuditRecorder;

    $recorder->record('create_application', application: $first);
    $recorder->record('status_change:FILED', application: $second);

    expect($recorder->forApplication($first)->pluck('action')->all())->toBe(['create_application']);
});
