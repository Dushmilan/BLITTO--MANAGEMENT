<?php

namespace App\Domain\Filing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\LifecycleService;
use App\Domain\Notification\NotificationService;
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
    ) {}

    public function filingRecord(Application $application): ?FilingRecord
    {
        return FilingRecord::query()->where('application_id', $application->id)->first();
    }

    public function markFiled(Application $application, User $actor): Application
    {
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

        $this->audit->record('filing:mark_filed', actor: $actor, application: $application);

        return $application;
    }

    /**
     * @throws AcknowledgementWithoutFiling when the application was never filed
     */
    public function acknowledgeNipo(Application $application, User $actor): Application
    {
        $record = $this->filingRecord($application)
            ?? throw new AcknowledgementWithoutFiling($application->id);

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

        $this->audit->record('filing:acknowledge_nipo', actor: $actor, application: $application);

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
    public function recordDefectSheet(Application $application, int $sheetNumber, string $description, User $actor): DefectSheet
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

        $this->audit->record('filing:defect_sheet', actor: $actor, application: $application);

        return $sheet;
    }
}
