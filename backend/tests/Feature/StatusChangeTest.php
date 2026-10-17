<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Inventor;
use App\Models\Notification;
use App\Models\User;

it('notifies every inventor and audits the change', function () {
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Filed', 'inventor_email' => 'ada@pdn.ac.lk']);

    foreach (['ada@pdn.ac.lk', 'bob@pdn.ac.lk'] as $email) {
        Inventor::query()->create([
            'application_id' => $application->id,
            'inventor_name' => 'Inventor',
            'inventor_email' => $email,
        ]);
    }

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'FILED',
        'reason' => 'NIPO receipt recorded',
    ])->assertNoContent();

    expect($application->refresh()->status)->toBe('FILED')
        ->and(Notification::query()->count())->toBe(2)
        ->and(Notification::query()->pluck('recipient_email')->sort()->values()->all())
        ->toBe(['ada@pdn.ac.lk', 'bob@pdn.ac.lk'])
        ->and(Notification::query()->first()->subject)->toBe("Patent {$application->id} filed to NIPO")
        ->and(AuditLog::query()->where('action', 'status_change:FILED')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'status_change:FILED')->sole()->user_id)->toBe($director->id);
});

it('rejects an unknown status with 422 instead of a server error', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Bogus']);

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'SOMEDAY',
        'reason' => 'typo',
    ])->assertStatus(422)->assertJsonValidationErrors('new_status');

    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});

it('writes no notification or audit row when the transition is illegal', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Jump', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'GRANTED',
        'reason' => 'too early',
    ])->assertStatus(422);

    expect($application->refresh()->status)->toBe('DRAFT');
    $this->assertDatabaseCount('notifications', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});

it('audits the actor email in the status history', function () {
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Trail']);

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'FILED',
        'reason' => 'Filed receipt recorded',
    ])->assertNoContent();

    $this->assertDatabaseHas('status_history', [
        'application_id' => $application->id,
        'old_status' => 'DRAFT',
        'new_status' => 'FILED',
        'changed_by' => 'director@pdn.ac.lk',
        'reason' => 'Filed receipt recorded',
    ]);
});
