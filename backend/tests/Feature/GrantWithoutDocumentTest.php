<?php

use App\Models\Application;
use App\Models\FilingRecord;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('warns and notifies the admin when granting without a document', function () {
    Storage::fake('vault');
    config(['blitto.bootstrap_admin_email' => 'admin@pdn.ac.lk']);

    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'No document', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/file")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/acknowledge")->assertOk();

    $response = $this->actingAs($md)
        ->postJson("/admin/filing/{$application->id}/grant?patent_number=LK/PAT/2026/1")
        ->assertOk()
        ->assertJsonPath('status', 'GRANTED');

    expect($response->json('warning'))->toContain('no document');

    $this->assertDatabaseHas('notifications', [
        'recipient_email' => 'admin@pdn.ac.lk',
        'kind' => 'grant_without_document',
    ]);
});

it('grants without a warning once a document exists', function () {
    Storage::fake('vault');
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Documented', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/file")->assertOk();
    $this->actingAs($md)->postJson("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('patent.pdf', 'bytes'),
    ])->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/acknowledge")->assertOk();

    $response = $this->actingAs($md)
        ->postJson("/admin/filing/{$application->id}/grant?patent_number=LK/PAT/2026/2")
        ->assertOk();

    expect($response->json('warning'))->toBeNull()
        ->and(Notification::query()->where('kind', 'grant_without_document')->count())->toBe(0);

    expect(FilingRecord::query()->sole()->patent_number)->toBe('LK/PAT/2026/2');
});

it('audits grant_without_document when the filing path grants without a document', function () {
    Storage::fake('vault');
    config(['blitto.bootstrap_admin_email' => 'admin@pdn.ac.lk']);

    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Bare grant', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/file")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/acknowledge")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/grant?patent_number=LK/PAT/2026/11")->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'grant_without_document',
        'application_id' => $application->id,
        'user_id' => $md->id,
        'ip_address' => '127.0.0.1',
    ]);
});
