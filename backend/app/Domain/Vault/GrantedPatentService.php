<?php

namespace App\Domain\Vault;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Authorization\Role;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use ZipArchive;

/**
 * Inventor-facing granted-patent download — port of
 * `app/api/routes.py::download_granted_patent`. One document streams as
 * itself; two or more become a ZIP.
 */
final class GrantedPatentService
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly AuditRecorder $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @throws NotYourPatent
     * @throws PatentNotGranted
     */
    public function downloadPayload(
        Application $application,
        User $viewer,
        ?string $ipAddress = null,
    ): GrantedDownload {
        $this->assertDownloadable($application, $viewer);

        $documents = $this->documents->listFor($application);

        if ($documents->isEmpty()) {
            throw DocumentPending::forApplication();
        }

        $stamp = Carbon::now()->utc()->format('Ymd\THis\Z');

        $this->audit->record(
            'download_granted_patent',
            actor: $viewer,
            application: $application,
            ipAddress: $ipAddress,
        );

        if ($documents->count() === 1) {
            $document = $documents->first();

            return new GrantedDownload(
                downloadName: $this->grantedName($document->filename, $stamp),
                contentType: DocumentFilename::mimeType($document->filename),
                bytes: $this->documents->bytesFor($document),
            );
        }

        return new GrantedDownload(
            downloadName: "{$application->id}_granted_{$stamp}.zip",
            contentType: 'application/zip',
            zipPath: $this->zip($documents),
        );
    }

    /**
     * @param  Collection<int, Document>  $documents
     */
    private function zip(Collection $documents): string
    {
        // ZipArchive can only write to a real file, so build one and hand
        // ownership to the response (deleteFileAfterSend).
        $scratch = (string) tempnam(sys_get_temp_dir(), 'blitto-docs');
        $path = $scratch.'.zip';
        unlink($scratch);

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        /** @var array<string, int> $usedEntryNames */
        $usedEntryNames = [];

        foreach ($documents as $document) {
            $bytes = $this->documents->tryBytesFor($document);

            if ($bytes !== null) {
                $zip->addFromString($this->uniqueEntryName($document->filename, $usedEntryNames), $bytes);
            }
        }

        $zip->close();

        return $path;
    }

    /**
     * @throws NotYourPatent
     * @throws DocumentRequestRefused
     * @throws DocumentAlreadyRequested
     */
    public function requestDocument(Application $application, User $viewer, ?string $ipAddress = null): void
    {
        if (Role::from($viewer->role) === Role::USER && $application->inventor_email !== $viewer->email) {
            throw new NotYourPatent('Not your patent');
        }

        if (ApplicationStatus::from($application->status) !== ApplicationStatus::GRANTED) {
            throw new DocumentRequestRefused('Patent is not GRANTED');
        }

        if ($this->documents->hasDocuments($application)) {
            throw new DocumentRequestRefused('Document already available');
        }

        if ($this->alreadyRequested($application, $viewer)) {
            throw new DocumentAlreadyRequested('Document already requested');
        }

        $this->audit->record('document_requested', actor: $viewer, application: $application, ipAddress: $ipAddress);

        $this->notifications->send(
            $this->adminRecipient(),
            "Document requested for {$application->id}",
            "{$viewer->email} requested the granted patent document for application {$application->id} ({$application->title}).",
            'document_request',
        );
    }

    /**
     * The bootstrap admin inbox, or the fallback the accessor guarantees.
     */
    private function adminRecipient(): string
    {
        $configured = (string) config('blitto.bootstrap_admin_email');

        return $configured !== '' ? $configured : 'md@pdn.ac.lk';
    }

    private function alreadyRequested(Application $application, User $viewer): bool
    {
        return AuditLog::query()
            ->where('action', 'document_requested')
            ->where('user_id', $viewer->id)
            ->where('application_id', $application->id)
            ->exists();
    }

    /**
     * Ownership (invariant 6) is checked before the GRANTED gate so a stranger
     * never learns an application's status.
     *
     * @throws NotYourPatent
     * @throws PatentNotGranted
     */
    private function assertDownloadable(Application $application, User $viewer): void
    {
        if (Role::from($viewer->role) === Role::USER && $application->inventor_email !== $viewer->email) {
            throw new NotYourPatent('Not your patent');
        }

        if (ApplicationStatus::from($application->status) !== ApplicationStatus::GRANTED) {
            throw new PatentNotGranted('Download available only for GRANTED patents');
        }
    }

    private function grantedName(string $filename, string $stamp): string
    {
        $safe = DocumentFilename::safe($filename);
        $extension = DocumentRules::extensionOf($safe);
        $stem = $extension === '' ? $safe : substr($safe, 0, -(strlen($extension) + 1));

        return $extension === ''
            ? "{$stem}_granted_{$stamp}"
            : "{$stem}_granted_{$stamp}.{$extension}";
    }

    /**
     * Two documents can sanitize to the same basename; ZipArchive would
     * silently overwrite the first, so later entries get " (2)", " (3)": the
     * bundle must carry every document it promises.
     *
     * @param  array<string, int>  $used  running tally, keyed by entry name
     */
    private function uniqueEntryName(string $filename, array &$used): string
    {
        $candidate = DocumentFilename::safe($filename);

        if (isset($used[$candidate])) {
            $extension = DocumentRules::extensionOf($candidate);
            $stem = $extension === '' ? $candidate : substr($candidate, 0, -(strlen($extension) + 1));

            do {
                $used[$candidate]++;
                $candidate = $extension === ''
                    ? "{$stem} ({$used[$candidate]})"
                    : "{$stem} ({$used[$candidate]}).{$extension}";
            } while (isset($used[$candidate]));
        }

        $used[$candidate] = 1;

        return $candidate;
    }
}
