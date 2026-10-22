<?php

use App\Models\Application;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('uploads a document as staff', function (string $state) {
    Storage::fake('vault');
    $staff = User::factory()->{$state}()->create();
    $application = Application::query()->create(['title' => 'Upload']);

    $this->actingAs($staff)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('specification.pdf', '%PDF-1.4'),
    ])->assertOk()
        ->assertJsonPath('filename', 'specification.pdf')
        ->assertJsonPath('application_id', $application->id)
        ->assertJsonPath('uploaded_by', $staff->email);

    $document = Document::query()->sole();

    expect($document->file_size)->toBe(8)
        ->and(Storage::disk('vault')->exists($document->content_ref))->toBeTrue()
        ->and(Storage::disk('vault')->get($document->content_ref))->toBe('%PDF-1.4');
})->with(['director', 'md']);

it('requires a file, resolves the application last and applies the upload rules', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Rules']);

    // No file at all → validation 422, before any lookup.
    $this->actingAs($director)->postJson("/applications/{$application->id}/documents")
        ->assertStatus(422)->assertJsonValidationErrors('file');

    // Valid file, unknown application → 404.
    $this->actingAs($director)->postJson('/applications/unknown-app/documents', [
        'file' => UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
    ])->assertNotFound();

    // Disallowed extension → 422 with the allow-list in the message.
    $this->actingAs($director)->postJson("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('evil.exe', 'bytes'),
    ])->assertStatus(422)
        ->assertJsonPath('message', "File type '.exe' not allowed. Accepted: .csv, .doc, .docx, .jpeg, .jpg, .pdf, .png, .txt, .xls, .xlsx");

    // Over the cap → 413.
    $this->actingAs($director)->postJson("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'),
    ])->assertStatus(413)
        ->assertJsonPath('message', 'Document too large (max 10 MB)');

    expect(Document::query()->count())->toBe(0);
});

it('reports 413 when PHP rejected the upload for exceeding its ini limit', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Ini limit']);

    $path = (string) tempnam(sys_get_temp_dir(), 'ini-limit');
    file_put_contents($path, 'x');

    $rejectedByIni = new UploadedFile($path, 'huge.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);

    $this->actingAs($director)
        ->postJson("/applications/{$application->id}/documents", ['file' => $rejectedByIni])
        ->assertStatus(413)
        ->assertJsonPath('message', 'Document too large (max 10 MB)');

    @unlink($path);

    expect(Document::query()->count())->toBe(0);
});

it('lists the documents of one application for staff only', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Listed']);

    foreach (['first.pdf', 'second.pdf'] as $name) {
        $this->actingAs($director)->post("/applications/{$application->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent($name, 'bytes'),
        ])->assertOk();
    }

    $listed = $this->actingAs($director)
        ->getJson("/applications/{$application->id}/documents")
        ->assertOk()
        ->json();

    expect($listed)->toHaveCount(2)
        ->and($listed[0]['filename'])->toBe('first.pdf')
        ->and($listed[1]['filename'])->toBe('second.pdf');

    // Guest first: actingAs persists for the rest of the test.
    $this->app['auth']->forgetGuards();
    $this->getJson("/applications/{$application->id}/documents")->assertUnauthorized();

    $this->actingAs(User::factory()->create())->getJson("/applications/{$application->id}/documents")
        ->assertForbidden();
});
