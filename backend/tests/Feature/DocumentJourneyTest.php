<?php

use App\Models\Application;
use App\Models\Document;
use App\Models\Inventor;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('runs the J1 grant and download journey', function () {
    Storage::fake('vault');
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $inventor = User::factory()->create(['email' => 'j1@pdn.ac.lk']);

    $applicationId = $this->actingAs($md)->postJson('/applications', [
        'title' => 'J1 Widget',
        'inventor_name' => 'J One',
        'inventor_email' => 'j1@pdn.ac.lk',
    ])->assertOk()->json('id');

    // Guest first: actingAs persists for the rest of the test.
    $this->app['auth']->forgetGuards();
    $this->getJson("/applications/{$applicationId}/download")->assertUnauthorized();

    // A document may be added after filing — this is how the granted patent
    // reaches the vault.
    $this->actingAs($md)->postJson("/applications/{$applicationId}/documents", [
        'file' => UploadedFile::fake()->createWithContent('patent.pdf', 'PATENT'),
    ])->assertOk();

    $this->actingAs($md)->postJson("/admin/filing/{$applicationId}/file")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$applicationId}/acknowledge")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$applicationId}/grant?patent_number=LK/PAT/2026/77")
        ->assertOk()
        ->assertJsonPath('status', 'GRANTED');

    $download = $this->actingAs($inventor)->get("/applications/{$applicationId}/download")->assertOk();

    expect($download->getContent())->toBe('PATENT')
        ->and($download->headers->get('x-downloaded-at'))->not->toBeNull()
        ->and(Document::query()->count())->toBe(1);
});

it('runs the J2 pending document request journey', function () {
    Storage::fake('vault');
    config(['blitto.bootstrap_admin_email' => 'admin@pdn.ac.lk']);

    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $inventor = User::factory()->create(['email' => 'j2@pdn.ac.lk']);

    $application = Application::query()->create([
        'title' => 'J2 Widget',
        'inventor_email' => 'j2@pdn.ac.lk',
    ]);

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/file")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/acknowledge")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/grant?patent_number=LK/PAT/2026/78")->assertOk();

    // The inventor sees the pending state, cannot download, then requests it.
    $this->actingAs($inventor)->getJson("/applications/{$application->id}/download")
        ->assertStatus(409)
        ->assertJsonPath('code', 'document_pending');

    $this->actingAs($inventor)->postJson("/applications/{$application->id}/request-document")->assertOk();

    $this->assertDatabaseHas('notifications', ['kind' => 'document_request']);

    // Staff answer the request by uploading the patent document.
    $this->actingAs($md)->postJson("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('grant.pdf', 'GRANT'),
    ])->assertOk();

    $this->actingAs($inventor)->get("/applications/{$application->id}/download")->assertOk();

    expect(collect($this->actingAs($md)->getJson('/applications/missing-documents')->json()))->toBeEmpty();
});

it('runs the J4 multi-document zip journey', function () {
    Storage::fake('vault');
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $inventor = User::factory()->create(['email' => 'j4@pdn.ac.lk']);

    $application = Application::query()->create([
        'title' => 'J4 Widget',
        'status' => 'GRANTED',
        'inventor_email' => 'j4@pdn.ac.lk',
    ]);

    Inventor::query()->create([
        'application_id' => $application->id,
        'inventor_name' => 'J Four',
        'inventor_email' => 'j4@pdn.ac.lk',
    ]);

    foreach (['patent.pdf' => 'ONE', 'drawings.pdf' => 'TWO'] as $name => $contents) {
        $this->actingAs($md)->postJson("/applications/{$application->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent($name, $contents),
        ])->assertOk();
    }

    expect(Document::query()->count())->toBe(2)
        ->and(Notification::query()->where('kind', 'download_ready')->count())->toBe(1);

    $response = $this->actingAs($inventor)
        ->get("/applications/{$application->id}/download")
        ->assertOk()
        ->assertHeader('content-type', 'application/zip');

    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());

    expect($zip->getFromName('patent.pdf'))->toBe('ONE')
        ->and($zip->getFromName('drawings.pdf'))->toBe('TWO');

    $zip->close();
});
