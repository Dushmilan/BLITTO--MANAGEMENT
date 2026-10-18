<?php

namespace App\Domain\Vault;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Authorization\Role;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Models\Application;
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

        foreach ($documents as $document) {
            $bytes = $this->documents->tryBytesFor($document);

            if ($bytes !== null) {
                $zip->addFromString(DocumentFilename::safe($document->filename), $bytes);
            }
        }

        $zip->close();

        return $path;
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
}
