<?php

use App\Domain\Vault\DocumentImmutability;
use App\Domain\Vault\DocumentIsImmutable;
use App\Models\Application;
use App\Models\Document;
use App\Models\FilingRecord;

it('rejects modifying a document once its application is filed', function () {
    $application = Application::query()->create(['title' => 'Filed application']);
    $document = Document::query()->create([
        'application_id' => $application->id,
        'filename' => 'specification.pdf',
        'content_ref' => 'original-bytes',
        'uploaded_by' => 'director@pdn.ac.lk',
    ]);
    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => '2026-10-01 00:00:00',
        'filed_by' => 'director@pdn.ac.lk',
    ]);

    (new DocumentImmutability)->assertModifiable($document);
})->throws(DocumentIsImmutable::class);

it('allows modifying a document before its application is filed', function () {
    $application = Application::query()->create(['title' => 'Draft application']);
    $document = Document::query()->create([
        'application_id' => $application->id,
        'filename' => 'draft-spec.pdf',
        'content_ref' => 'draft-bytes',
        'uploaded_by' => 'director@pdn.ac.lk',
    ]);

    (new DocumentImmutability)->assertModifiable($document);

    expect($document->content_ref)->toBe('draft-bytes');
});
