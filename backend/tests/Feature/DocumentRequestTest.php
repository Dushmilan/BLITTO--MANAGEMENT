<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('accepts one document request per user and notifies the admin', function () {
    Storage::fake('vault');
    config(['blitto.bootstrap_admin_email' => 'admin@pdn.ac.lk']);

    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $application = Application::query()->create([
        'title' => 'Pending patent',
        'status' => 'GRANTED',
        'inventor_email' => 'owner@pdn.ac.lk',
    ]);

    $this->actingAs($owner)
        ->postJson("/applications/{$application->id}/request-document")
        ->assertOk()
        ->assertJsonPath('status', 'requested')
        ->assertJsonPath('application_id', $application->id);

    $notification = Notification::query()->sole();

    expect($notification->recipient_email)->toBe('admin@pdn.ac.lk')
        ->and($notification->kind)->toBe('document_request');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'document_requested',
        'user_id' => $owner->id,
        'application_id' => $application->id,
    ]);

    // A second request by the same user is refused for good.
    $this->actingAs($owner)
        ->postJson("/applications/{$application->id}/request-document")
        ->assertStatus(429)
        ->assertJsonPath('message', 'Document already requested');

    expect(Notification::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'document_requested')->count())->toBe(1);
});

it('falls back to the md address when no admin address is configured', function () {
    Storage::fake('vault');
    config(['blitto.bootstrap_admin_email' => '']);

    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $application = Application::query()->create([
        'title' => 'Fallback',
        'status' => 'GRANTED',
        'inventor_email' => 'owner@pdn.ac.lk',
    ]);

    $this->actingAs($owner)->postJson("/applications/{$application->id}/request-document")->assertOk();

    expect(Notification::query()->sole()->recipient_email)->toBe('md@pdn.ac.lk');
});

it('refuses requests for patents that are not granted or already documented', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $other = User::factory()->create(['email' => 'other@pdn.ac.lk']);

    $draft = Application::query()->create(['title' => 'Draft', 'inventor_email' => 'owner@pdn.ac.lk']);
    $granted = Application::query()->create([
        'title' => 'Documented',
        'status' => 'GRANTED',
        'inventor_email' => 'owner@pdn.ac.lk',
    ]);

    $this->actingAs($director)->postJson("/applications/{$granted->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('patent.pdf', 'bytes'),
    ])->assertOk();

    // Not GRANTED.
    $this->actingAs($owner)->postJson("/applications/{$draft->id}/request-document")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Patent is not GRANTED');

    // Already documented.
    $this->actingAs($owner)->postJson("/applications/{$granted->id}/request-document")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Document already available');

    // Someone else's patent.
    $this->actingAs($other)->postJson("/applications/{$draft->id}/request-document")
        ->assertForbidden();

    // Nothing was written by the refusals (the granted upload's own
    // download_ready notification is a different kind).
    expect(Notification::query()->where('kind', 'document_request')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'document_requested')->count())->toBe(0);
});
