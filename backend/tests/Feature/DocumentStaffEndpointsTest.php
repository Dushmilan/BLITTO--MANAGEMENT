<?php

use App\Models\Application;
use App\Models\Document;
use App\Models\FilingRecord;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('lists only granted applications that have no documents for staff', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();

    $grantedEmpty = Application::query()->create(['title' => 'Granted empty', 'status' => 'GRANTED']);
    $grantedWithDoc = Application::query()->create(['title' => 'Granted with doc', 'status' => 'GRANTED']);
    Application::query()->create(['title' => 'Filed empty', 'status' => 'FILED']);

    $this->actingAs($director)->postJson("/applications/{$grantedWithDoc->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('patent.pdf', 'bytes'),
    ])->assertOk();

    $ids = collect($this->actingAs($director)->getJson('/applications/missing-documents')->assertOk()->json())
        ->pluck('id');

    expect($ids)->toContain($grantedEmpty->id)
        ->and($ids)->not->toContain($grantedWithDoc->id)
        ->and($ids)->toHaveCount(1);

    $this->app['auth']->forgetGuards();
    $this->getJson('/applications/missing-documents')->assertUnauthorized();

    $this->actingAs(User::factory()->create())->getJson('/applications/missing-documents')->assertForbidden();
});

it('downloads a single document with a sanitized disposition', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Disposition']);

    $this->actingAs($director)->postJson("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('../../../evil.pdf', 'payload'),
    ])->assertOk();

    $document = Document::query()->sole();

    $response = $this->actingAs($director)
        ->get("/applications/{$application->id}/documents/{$document->id}/download")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $disposition = (string) $response->headers->get('content-disposition');

    expect($response->getContent())->toBe('payload')
        ->and($disposition)->toBe('attachment; filename="evil.pdf"')
        ->and(substr_count($disposition, '"'))->toBe(2)
        ->and($disposition)->not->toContain('..', "\r", "\n");
});

it('returns 404 when a document has lost its stored bytes', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Lost bytes']);

    $this->actingAs($director)->postJson("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('spec.pdf', 'payload'),
    ])->assertOk();

    $document = Document::query()->sole();
    Storage::disk('vault')->delete($document->content_ref);

    $this->actingAs($director)
        ->getJson("/applications/{$application->id}/documents/{$document->id}/download")
        ->assertNotFound();
});

it('returns 404 for a document scoped to another application', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $owner = Application::query()->create(['title' => 'Owner']);
    $other = Application::query()->create(['title' => 'Other']);

    $this->actingAs($director)->postJson("/applications/{$owner->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
    ])->assertOk();

    $document = Document::query()->sole();

    $this->actingAs($director)
        ->getJson("/applications/{$other->id}/documents/{$document->id}/download")
        ->assertNotFound();
});

it('deletes an unfiled document as staff and refuses a filed one', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Deletes']);

    $this->actingAs($director)->postJson("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
    ])->assertOk();

    $unfiled = Document::query()->sole();

    $this->actingAs($director)
        ->deleteJson("/applications/{$application->id}/documents/{$unfiled->id}")
        ->assertNoContent();

    expect(Document::query()->count())->toBe(0);

    $this->actingAs($director)->postJson("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
    ])->assertOk();

    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => '2026-10-01 00:00:00',
        'filed_by' => 'director@pdn.ac.lk',
    ]);

    $filed = Document::query()->sole();

    $this->actingAs($director)
        ->deleteJson("/applications/{$application->id}/documents/{$filed->id}")
        ->assertStatus(409)
        ->assertJsonPath('message', "Document {$filed->id} is immutable: its application has been filed");

    expect(Document::query()->count())->toBe(1);

    $this->actingAs(User::factory()->create())
        ->deleteJson("/applications/{$application->id}/documents/{$filed->id}")
        ->assertForbidden();
});

it('gates the staff document routes', function () {
    Storage::fake('vault');
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Gated']);

    $this->actingAs($director)->postJson("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
    ])->assertOk();

    $document = Document::query()->sole();
    $user = User::factory()->create();

    $guarded = [
        ['GET', '/applications/missing-documents'],
        ['GET', "/applications/{$application->id}/documents"],
        ['GET', "/applications/{$application->id}/documents/{$document->id}/download"],
        ['DELETE', "/applications/{$application->id}/documents/{$document->id}"],
    ];

    foreach ($guarded as [$method, $uri]) {
        $this->app['auth']->forgetGuards();
        $this->json($method, $uri)->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->json($method, $uri)->assertForbidden();
    }
});
