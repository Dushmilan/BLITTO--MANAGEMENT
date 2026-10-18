<?php

use App\Domain\Vault\DocumentIsImmutable;
use App\Domain\Vault\DocumentNotFound;
use App\Domain\Vault\DocumentService;
use App\Models\Application;
use App\Models\Document;
use App\Models\FilingRecord;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('finds a document only through its own application', function () {
    Storage::fake('vault');
    $owner = Application::query()->create(['title' => 'Owner']);
    $other = Application::query()->create(['title' => 'Other']);
    $service = app(DocumentService::class);

    $document = $service->store(
        $owner,
        UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
        User::factory()->director()->create(),
    );

    expect($service->findForApplication($owner, $document->id)->id)->toBe($document->id);

    expect(fn () => $service->findForApplication($other, $document->id))
        ->toThrow(DocumentNotFound::class);
});

it('deletes an unfiled document and its bytes', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Draft']);
    $service = app(DocumentService::class);
    $director = User::factory()->director()->create();

    $document = $service->store(
        $application,
        UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
        $director,
    );

    $service->delete($document, $director);

    expect(Document::query()->count())->toBe(0)
        ->and(Storage::disk('vault')->exists($document->content_ref))->toBeFalse()
        ->and($service->hasDocuments($application))->toBeFalse();
});

it('audits a deleted document', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Audited delete']);
    $service = app(DocumentService::class);
    $director = User::factory()->director()->create();

    $document = $service->store(
        $application,
        UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
        $director,
    );

    $service->delete($document, $director, '10.0.0.7');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'delete_document',
        'application_id' => $application->id,
        'ip_address' => '10.0.0.7',
    ]);
});

it('refuses to delete a document once the application is filed', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Filed']);
    $service = app(DocumentService::class);
    $director = User::factory()->director()->create();

    $document = $service->store(
        $application,
        UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
        $director,
    );

    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => '2026-10-01 00:00:00',
        'filed_by' => 'director@pdn.ac.lk',
    ]);

    expect(fn () => $service->delete($document, $director))->toThrow(DocumentIsImmutable::class);

    expect(Document::query()->count())->toBe(1)
        ->and(Storage::disk('vault')->exists($document->content_ref))->toBeTrue();
});
