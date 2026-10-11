<?php

namespace App\Domain\Lifecycle;

use App\Models\Application;
use App\Models\StatusHistory;

/**
 * Enforces the canonical prosecution lifecycle transition table.
 */
class LifecycleService
{
    /**
     * Allowed target states per source state.
     *
     * Reference: pre-laravel-final-state `app/modules/application_intake/local/`
     * `_ALLOWED_TRANSITIONS`. REJECTED and MAINTENANCE are terminal.
     *
     * @var array<string, list<string>>
     */
    private const array TRANSITIONS = [
        'DRAFT' => ['FILED'],
        'FILED' => ['PUBLISHED', 'ACKNOWLEDGED', 'EXAMINATION', 'DEFECT_SHEET_1', 'REJECTED'],
        'PUBLISHED' => ['EXAMINATION', 'ACKNOWLEDGED', 'REJECTED'],
        'ACKNOWLEDGED' => ['EXAMINATION', 'DEFECT_SHEET_1', 'DEFECT_SHEET_2', 'DEFECT_SHEET_3', 'GRANTED', 'REJECTED'],
        'DEFECT_SHEET_1' => ['DEFECT_SHEET_2', 'ACKNOWLEDGED', 'EXAMINATION', 'REJECTED'],
        'DEFECT_SHEET_2' => ['DEFECT_SHEET_3', 'ACKNOWLEDGED', 'EXAMINATION', 'REJECTED'],
        'DEFECT_SHEET_3' => ['ACKNOWLEDGED', 'EXAMINATION', 'REJECTED'],
        'EXAMINATION' => ['GRANTED', 'REJECTED', 'ACKNOWLEDGED'],
        'GRANTED' => ['MAINTENANCE'],
        'REJECTED' => [],
        'MAINTENANCE' => [],
    ];

    /**
     * @throws IllegalTransition when $newStatus may not follow the current status
     */
    public function changeStatus(Application $application, ApplicationStatus $newStatus, string $changedBy, string $reason): Application
    {
        $oldStatus = ApplicationStatus::from($application->status);

        $this->assertAllowed($oldStatus, $newStatus);

        $application->status = $newStatus->value;
        $application->save();

        StatusHistory::query()->create([
            'application_id' => $application->id,
            'old_status' => $oldStatus->value,
            'new_status' => $newStatus->value,
            'changed_by' => $changedBy,
            'reason' => $reason,
        ]);

        return $application;
    }

    /**
     * @throws IllegalTransition when $from may not move to $to
     */
    public function assertAllowed(ApplicationStatus $from, ApplicationStatus $to): void
    {
        // Same-status re-entry is always allowed (idempotent no-op).
        if ($from === $to) {
            return;
        }

        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw new IllegalTransition("Illegal transition {$from->value}->{$to->value}");
        }
    }
}
