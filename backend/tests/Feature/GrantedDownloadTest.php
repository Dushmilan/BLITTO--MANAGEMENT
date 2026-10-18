<?php

use App\Models\Application;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function grantedApplication(array $attributes = []): Application
{
    return Application::query()->create(array_merge([
        'title' => 'Granted invention',
        'status' => 'GRANTED',
        'inventor_email' => 'owner@pdn.ac.lk',
    ], $attributes));
}

function uploadAs(User $staff, Application $application, string $name, string $contents): Document
{
    test()->actingAs($staff)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent($name, $contents),
    ])->assertOk();

    return Document::query()->where('filename', $name)->sole();
}

it('lets the owning inventor download the granted patent', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $application = grantedApplication();

    uploadAs($director, $application, 'patent.pdf', 'granted bytes');

    $response = $this->actingAs($owner)
        ->get("/applications/{$application->id}/download")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $disposition = (string) $response->headers->get('content-disposition');

    expect($response->getContent())->toBe('granted bytes')
        ->and($disposition)->toStartWith('attachment; filename="patent_granted_')
        ->and($disposition)->toEndWith('.pdf"')
        ->and($response->headers->get('x-downloaded-at'))->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'download_granted_patent',
        'user_id' => $owner->id,
        'application_id' => $application->id,
    ]);
});

it('zips every document of a multi-document granted patent', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $application = grantedApplication();

    uploadAs($director, $application, 'patent.pdf', 'first bytes');
    uploadAs($director, $application, 'drawings.pdf', 'second bytes');

    $response = $this->actingAs($owner)
        ->get("/applications/{$application->id}/download")
        ->assertOk()
        ->assertHeader('content-type', 'application/zip');

    expect($response->headers->get('content-disposition'))
        ->toStartWith('attachment; filename="'.$application->id.'_granted_')
        ->toEndWith('.zip"');

    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());

    expect($zip->numFiles)->toBe(2)
        ->and($zip->getFromName('patent.pdf'))->toBe('first bytes')
        ->and($zip->getFromName('drawings.pdf'))->toBe('second bytes');

    $zip->close();
});

it('refuses downloads that are not granted or not owned', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $other = User::factory()->create(['email' => 'other@pdn.ac.lk']);

    $draft = Application::query()->create(['title' => 'Draft', 'inventor_email' => 'owner@pdn.ac.lk']);
    $granted = grantedApplication();

    // Guest first: actingAs persists for the rest of the test.
    $this->getJson("/applications/{$granted->id}/download")->assertUnauthorized();

    $this->actingAs($owner)->getJson("/applications/{$draft->id}/download")->assertForbidden();
    $this->actingAs($other)->getJson("/applications/{$granted->id}/download")->assertForbidden();
    $this->actingAs($director)->getJson("/applications/{$draft->id}/download")->assertForbidden();
});

it('reports document_pending for a granted patent with no documents', function () {
    Storage::fake('vault');
    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $application = grantedApplication();

    $this->actingAs($owner)->getJson("/applications/{$application->id}/download")
        ->assertStatus(409)
        ->assertJsonPath('code', 'document_pending')
        ->assertJsonPath('message', 'Patent is GRANTED but the document has not been uploaded yet. You can request it via POST /applications/{application_id}/request-document.');
});

it('still returns a zip when one document has lost its bytes', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $application = grantedApplication();

    uploadAs($director, $application, 'patent.pdf', 'first bytes');
    $lost = uploadAs($director, $application, 'lost.pdf', 'second bytes');

    Storage::disk('vault')->delete($lost->content_ref);

    $response = $this->actingAs($owner)->get("/applications/{$application->id}/download")->assertOk();

    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());

    expect($zip->numFiles)->toBe(1)
        ->and($zip->getFromName('patent.pdf'))->toBe('first bytes');

    $zip->close();
});
