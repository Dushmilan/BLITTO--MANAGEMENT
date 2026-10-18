<?php

namespace App\Domain\Filing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\IllegalTransition;
use App\Domain\Lifecycle\LifecycleService;
use App\Domain\Notification\NotificationService;
use App\Domain\Vault\DocumentService;
use App\Models\Application;
use App\Models\DefectSheet;
use App\Models\FilingRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Filing workflow — port of pre-laravel-final-state
 * `app/modules/filing_workflow/local/__init__.py`. Every step changes the
 * status through the lifecycle domain, notifies every inventor and writes
 * its own audit row (invariants 2 and 5).
 */
final class FilingWorkflowService
{
    public function __construct(
        private readonly LifecycleService $lifecycle,
        private readonly NotificationService $notifications,
        private readonly AuditRecorder $audit,
        private readonly DocumentService $documents,
    ) {}

    public function filingRecord(Application $application): ?FilingRecord
    {
        return FilingRecord::query()->where('application_id', $application->id)->first();
    }

    public function markFiled(Application $application, User $actor, ?string $ipAddress = null): Application
    {
        if ($application->status === ApplicationStatus::FILED->value) {
            return $application;
        }

        $this->lifecycle->changeStatus(
            $application,
            ApplicationStatus::FILED,
            changedBy: (string) $actor->email,
            reason: 'Application filed with NIPO',
        );

        FilingRecord::query()->updateOrCreate(
            ['application_id' => $application->id],
            ['filed_date' => Carbon::now(), 'filed_by' => (string) $actor->email],
        );

        $this->notifications->notifyInventors(
            $application,
            'Patent Filed',
            "Your patent application {$application->title} ({$this->reference($application)}) has been filed with NIPO.",
        );

        $this->audit->record('filing:mark_filed', actor: $actor, application: $application, ipAddress: $ipAddress);

        return $application;
    }

    /**
     * @throws AcknowledgementWithoutFiling when the application was never filed
     */
    public function acknowledgeNipo(Application $application, User $actor, ?string $ipAddress = null): Application
    {
        $record = $this->filingRecord($application)
            ?? throw new AcknowledgementWithoutFiling($application->id);

        if ($application->status === ApplicationStatus::ACKNOWLEDGED->value) {
            return $application;
        }

        $this->lifecycle->changeStatus(
            $application,
            ApplicationStatus::ACKNOWLEDGED,
            changedBy: (string) $actor->email,
            reason: 'NIPO acknowledgement recorded',
        );

        $record->nipo_acknowledged_at = Carbon::now();
        $record->nipo_acknowledged_by = (string) $actor->email;
        $record->save();

        $this->notifications->notifyInventors(
            $application,
            'Acknowledged',
            "NIPO has acknowledged receipt of your patent application {$application->title} ({$this->reference($application)}).",
        );

        $this->audit->record('filing:acknowledge_nipo', actor: $actor, application: $application, ipAddress: $ipAddress);

        return $application;
    }

    public function markGranted(Application $application, string $patentNumber, User $actor, ?string $ipAddress = null): Application
    {
        $this->lifecycle->changeStatus(
            $application,
            ApplicationStatus::GRANTED,
            changedBy: (string) $actor->email,
            reason: "Patent granted ({$patentNumber})",
        );

        $record = $this->filingRecord($application);

        if ($record !== null) {
            $record->granted_at = Carbon::now();
            $record->granted_by = (string) $actor->email;
            $record->patent_number = $patentNumber;
            $record->save();
        }

        $this->notifications->notifyInventors(
            $application,
            'Patent Received',
            "Your patent {$application->title} has been granted with patent number {$patentNumber}.",
        );

        $this->audit->record('filing:granted', actor: $actor, application: $application, ipAddress: $ipAddress);

        $this->warnWhenGrantedWithoutDocument($application);

        return $application;
    }

    /**
     * A granted patent with no document cannot be downloaded yet. Returns the
     * warning a caller should surface, or null when the download will work.
     */
    public function grantWarning(Application $application): ?string
    {
        if (ApplicationStatus::from($application->status) !== ApplicationStatus::GRANTED) {
            return null;
        }

        if ($this->documents->hasDocuments($application)) {
            return null;
        }

        return "Patent {$application->id} was granted but has no document yet; the inventor cannot download it until one is uploaded.";
    }

    private function warnWhenGrantedWithoutDocument(Application $application): void
    {
        $warning = $this->grantWarning($application);

        if ($warning === null) {
            return;
        }

        $this->notifications->send(
            (string) (config('blitto.bootstrap_admin_email') ?: 'md@pdn.ac.lk'),
            "Patent {$application->id} granted without a document",
            $warning,
            'grant_without_document',
        );
    }

    public function markRejected(Application $application, User $actor, ?string $ipAddress = null): Application
    {
        $this->lifecycle->changeStatus(
            $application,
            ApplicationStatus::REJECTED,
            changedBy: (string) $actor->email,
            reason: 'Application rejected',
        );

        $record = $this->filingRecord($application);

        if ($record !== null) {
            $record->rejected_at = Carbon::now();
            $record->rejected_by = (string) $actor->email;
            $record->save();
        }

        $this->notifications->notifyInventors(
            $application,
            'Rejected',
            "Your patent application {$application->title} ({$this->reference($application)}) has been rejected.",
        );

        $this->audit->record('filing:rejected', actor: $actor, application: $application, ipAddress: $ipAddress);

        return $application;
    }

    private function reference(Application $application): string
    {
        return substr($application->id, 0, 8);
    }

    /**
     * @return Collection<int, DefectSheet>
     */
    public function defectSheets(Application $application): Collection
    {
        return DefectSheet::query()
            ->where('application_id', $application->id)
            ->orderBy('sheet_number')
            ->get();
    }

    /**
     * @throws DefectSheetRejected
     * @throws IllegalTransition when the current status may not reach DEFECT_SHEET_{n}
     */
    public function recordDefectSheet(Application $application, int $sheetNumber, string $description, User $actor, ?string $ipAddress = null): DefectSheet
    {
        if (! in_array($sheetNumber, [1, 2, 3], true)) {
            throw new DefectSheetRejected("Invalid defect sheet number: {$sheetNumber} (must be 1-3)");
        }

        $existing = $this->defectSheets($application);

        if ($existing->contains(fn (DefectSheet $sheet): bool => $sheet->sheet_number === $sheetNumber)) {
            throw new DefectSheetRejected("Defect sheet #{$sheetNumber} already exists for {$application->id}");
        }

        if ($existing->count() >= 3) {
            throw new DefectSheetRejected("Maximum 3 defect sheets allowed for {$application->id}");
        }

        $this->lifecycle->changeStatus(
            $application,
            ApplicationStatus::from("DEFECT_SHEET_{$sheetNumber}"),
            changedBy: (string) $actor->email,
            reason: "Defect sheet #{$sheetNumber} recorded",
        );

        $sheet = DefectSheet::query()->create([
            'application_id' => $application->id,
            'sheet_number' => $sheetNumber,
            'description' => $description,
        ]);

        $record = $this->filingRecord($application);

        if ($record !== null) {
            $record->defect_sheet_count = $existing->count() + 1;
            $record->save();
        }

        $this->notifications->notifyInventors(
            $application,
            'Defect Sheet Received',
            "Defect sheet #{$sheetNumber} has been received for your patent application {$application->title} ({$this->reference($application)}): {$description}",
        );

        $this->audit->record('filing:defect_sheet', actor: $actor, application: $application, ipAddress: $ipAddress);

        return $sheet;
    }
}
