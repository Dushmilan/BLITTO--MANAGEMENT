<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('warns the inventor and the admin when a status change grants without a document', function () {
    Storage::fake('vault');
    config(['blitto.bootstrap_admin_email' => 'admin@pdn.ac.lk']);

    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create([
        'title' => 'Granted bare',
        'inventor_email' => 'ada@pdn.ac.lk',
        'status' => 'ACKNOWLEDGED',
    ]);

    $this->actingAs($md)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'GRANTED',
        'reason' => 'Granted by NIPO',
    ])->assertNoContent();

    $inventor = Notification::query()->where('recipient_email', 'ada@pdn.ac.lk')->sole();

    expect($inventor->subject)->toBe("Patent {$application->id} GRANTED — document pending")
        ->and($inventor->body)->toBe("Good news — patent {$application->id} is now GRANTED. The document is pending upload by BLITTO; you can request it from your portal and we will notify you when it is ready to download.")
        ->and($inventor->kind)->toBe('status_change');

    $this->assertDatabaseHas('notifications', [
        'recipient_email' => 'admin@pdn.ac.lk',
        'kind' => 'grant_without_document',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'grant_without_document',
        'application_id' => $application->id,
        'ip_address' => '127.0.0.1',
    ]);
});

it('keeps the download-ready wording when the granted application already has a document', function () {
    Storage::fake('vault');

    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create([
        'title' => 'Granted documented',
        'inventor_email' => 'ada@pdn.ac.lk',
        'status' => 'ACKNOWLEDGED',
    ]);

    $this->actingAs($md)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('patent.pdf', 'bytes'),
    ])->assertOk();

    $this->actingAs($md)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'GRANTED',
        'reason' => 'Granted by NIPO',
    ])->assertNoContent();

    expect(Notification::query()->where('recipient_email', 'ada@pdn.ac.lk')->sole()->subject)
        ->toBe("Patent {$application->id} GRANTED — download ready")
        ->and(Notification::query()->where('kind', 'grant_without_document')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'grant_without_document')->count())->toBe(0);
});
