<?php

namespace App\Domain\Filing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\LifecycleService;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\FilingRecord;
use App\Models\User;
use Illuminate\Support\Carbon;

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
}
