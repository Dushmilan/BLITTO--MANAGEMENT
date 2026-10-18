<?php

use App\Domain\Vault\DocumentService;
use App\Domain\Vault\DocumentStore;
use App\Domain\Vault\DocumentStoreFailure;
use App\Domain\Vault\DocumentTooLarge;
use App\Domain\Vault\InvalidDocumentFilename;
use App\Domain\Vault\UnsupportedFileType;
use App\Models\Application;
use App\Models\Document;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function documentStaff(): User
{
    return User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
}

it('accepts the allow-listed extensions case-insensitively', function (string $filename) {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Allowed']);
    $service = app(DocumentService::class);

    $document = $service->store(
        $application,
        UploadedFile::fake()->createWithContent($filename, 'bytes'),
        documentStaff(),
    );

    expect($document->filename)->toBe($filename)
        ->and($document->file_size)->toBe(5)
        ->and($document->uploaded_by)->toBe('director@pdn.ac.lk')
        ->and($service->hasDocuments($application))->toBeTrue();
})->with(['specification.pdf', 'TEST.PDF', 'notes.docx', 'sheet.XLSX', 'photo.jpeg']);

it('rejects disallowed and extensionless filenames', function (string $filename) {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Rejected']);

    expect(fn () => app(DocumentService::class)->store(
        $application,
        UploadedFile::fake()->createWithContent($filename, 'bytes'),
        documentStaff(),
    ))->toThrow(UnsupportedFileType::class);

    expect(Document::query()->count())->toBe(0)
        ->and(Storage::disk('vault')->allFiles())->toBe([]);
})->with(['evil.exe', 'no-extension', 'archive.tar.gz', 'script.php']);

it('rejects a filename longer than the column width', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Long name']);

    expect(fn () => app(DocumentService::class)->store(
        $application,
        UploadedFile::fake()->createWithContent(str_repeat('a', 252).'.pdf', 'bytes'),
        documentStaff(),
    ))->toThrow(InvalidDocumentFilename::class);

    expect(Document::query()->count())->toBe(0);
});

it('accepts a file of exactly the size cap and rejects one above it', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Sizes']);
    $service = app(DocumentService::class);
    $director = documentStaff();

    $atCap = $service->store(
        $application,
        UploadedFile::fake()->create('at-cap.pdf', 10240, 'application/pdf'),
        $director,
    );

    expect($atCap->file_size)->toBe(10 * 1024 * 1024);

    expect(fn () => $service->store(
        $application,
        UploadedFile::fake()->create('over-cap.pdf', 10241, 'application/pdf'),
        $director,
    ))->toThrow(DocumentTooLarge::class);

    expect(Document::query()->count())->toBe(1);
});

it('notifies inventors once when a granted application receives its first document', function () {
    Storage::fake('vault');
    $application = Application::query()->create([
        'title' => 'Granted',
        'status' => 'GRANTED',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);
    $service = app(DocumentService::class);
    $director = documentStaff();

    $service->store($application, UploadedFile::fake()->createWithContent('grant.pdf', 'a'), $director);
    $service->store($application, UploadedFile::fake()->createWithContent('grant-2.pdf', 'b'), $director);

    expect(Notification::query()->where('kind', 'download_ready')->count())->toBe(1)
        ->and(Notification::query()->sole()->recipient_email)->toBe('ada@pdn.ac.lk');
});

it('audits every stored document', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Audited']);
    $director = documentStaff();

    app(DocumentService::class)->store(
        $application,
        UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
        $director,
        '10.0.0.9',
    );

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'store_document',
        'application_id' => $application->id,
        'ip_address' => '10.0.0.9',
    ]);
});

it('writes no document row when the store refuses the bytes', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Unwritable']);

    $this->app->instance(DocumentStore::class, new class implements DocumentStore
    {
        public function put(string $contents): string
        {
            throw new DocumentStoreFailure('The vault disk refused the write.');
        }

        public function get(string $ref): ?string
        {
            return null;
        }

        public function delete(string $ref): bool
        {
            return false;
        }
    });

    expect(fn () => app(DocumentService::class)->store(
        $application,
        UploadedFile::fake()->createWithContent('spec.pdf', 'bytes'),
        documentStaff(),
    ))->toThrow(DocumentStoreFailure::class);

    expect(Document::query()->count())->toBe(0);
});
