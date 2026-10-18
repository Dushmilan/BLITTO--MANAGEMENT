<?php

namespace App\Domain\Vault;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Document storage rules — port of pre-laravel-final-state
 * `app/modules/document_vault/local/__init__.py` plus the upload route's
 * extension/size gates. Bytes live behind the DocumentStore port.
 */
final class DocumentService
{
    public function __construct(
        private readonly DocumentStore $store,
        private readonly AuditRecorder $audit,
        private readonly NotificationService $notifications,
        private readonly DocumentImmutability $immutability,
    ) {}

    public function store(
        Application $application,
        UploadedFile $file,
        User $actor,
        ?string $ipAddress = null,
    ): Document {
        $filename = (string) $file->getClientOriginalName();

        $this->assertFilenameLength($filename);
        $this->assertExtensionAllowed($filename);
        $this->assertWithinSizeCap((int) $file->getSize());

        $document = Document::query()->create([
            'application_id' => $application->id,
            'filename' => $filename,
            'file_size' => (int) $file->getSize(),
            'content_ref' => $this->store->put((string) $file->get()),
            'uploaded_by' => (string) $actor->email,
        ]);

        $this->audit->record('store_document', actor: $actor, application: $application, ipAddress: $ipAddress);

        $this->announceFirstGrantedDocument($application, $document);

        return $document;
    }

    /**
     * A GRANTED application that receives its first document tells the
     * inventors the patent is downloadable (reference: `send_download_ready`).
     */
    private function announceFirstGrantedDocument(Application $application, Document $document): void
    {
        if (ApplicationStatus::from($application->status) !== ApplicationStatus::GRANTED) {
            return;
        }

        $isFirst = Document::query()
            ->where('application_id', $application->id)
            ->whereKeyNot($document->id)
            ->doesntExist();

        if (! $isFirst) {
            return;
        }

        $this->notifications->notifyInventors(
            $application,
            'Patent document ready',
            "The granted patent document for application {$application->id} is now available to download.",
            'download_ready',
        );
    }

    /**
     * @throws InvalidDocumentFilename
     */
    private function assertFilenameLength(string $filename): void
    {
        if ($filename === '' || mb_strlen($filename) > DocumentRules::MAX_FILENAME_LENGTH) {
            throw new InvalidDocumentFilename(
                'Document filename must be between 1 and '.DocumentRules::MAX_FILENAME_LENGTH.' characters',
            );
        }
    }

    /**
     * @throws UnsupportedFileType
     */
    private function assertExtensionAllowed(string $filename): void
    {
        if (DocumentRules::isAllowed($filename)) {
            return;
        }

        $extension = DocumentRules::extensionOf($filename);

        throw new UnsupportedFileType(
            "File type '.{$extension}' not allowed. Accepted: ".DocumentRules::acceptedList(),
        );
    }

    /**
     * @throws DocumentTooLarge
     */
    private function assertWithinSizeCap(int $size): void
    {
        if ($size > DocumentRules::MAX_BYTES) {
            throw DocumentTooLarge::upload();
        }
    }

    /**
     * @throws DocumentNotFound when the document does not belong to this application
     */
    public function findForApplication(Application $application, string $documentId): Document
    {
        return Document::query()
            ->where('application_id', $application->id)
            ->whereKey($documentId)
            ->first()
            ?? throw DocumentNotFound::forApplication($documentId, $application->id);
    }

    /**
     * @throws DocumentIsImmutable when the application is already filed
     */
    public function delete(Document $document, User $actor, ?string $ipAddress = null): void
    {
        $this->immutability->assertModifiable($document);

        $application = Application::query()->find($document->application_id);

        $this->store->delete($document->content_ref);

        $document->delete();

        $this->audit->record('delete_document', actor: $actor, application: $application, ipAddress: $ipAddress);
    }

    /**
     * @return Collection<int, Document>
     */
    public function listFor(Application $application): Collection
    {
        return Document::query()
            ->where('application_id', $application->id)
            ->orderBy('uploaded_at')
            ->orderBy('id')
            ->get();
    }

    public function hasDocuments(Application $application): bool
    {
        return Document::query()->where('application_id', $application->id)->exists();
    }

    /**
     * Bytes for delivery, or null when the store no longer holds them.
     */
    public function tryBytesFor(Document $document): ?string
    {
        return $this->store->get($document->content_ref);
    }

    /**
     * Bytes for delivery.
     *
     * @throws DocumentContentMissing when the store no longer holds them
     */
    public function bytesFor(Document $document): string
    {
        return $this->tryBytesFor($document)
            ?? throw new DocumentContentMissing("Document content missing for {$document->id}");
    }

    /**
     * GRANTED applications with no document at all (staff worklist).
     *
     * @return Collection<int, Application>
     */
    public function missingGrantedDocuments(): Collection
    {
        return Application::query()
            ->where('status', ApplicationStatus::GRANTED->value)
            ->whereDoesntHave('documents')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
