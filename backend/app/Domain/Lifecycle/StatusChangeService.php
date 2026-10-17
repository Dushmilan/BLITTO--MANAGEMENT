<?php

namespace App\Domain\Lifecycle;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\User;

/**
 * Orchestrates a status change: the pure LifecycleService owns the
 * transition table and the status_history row; this service adds the two
 * cross-cutting obligations from CONTEXT.md — notify the inventors and
 * record the audit trail (invariants 5 and 6).
 */
final class StatusChangeService
{
    public function __construct(
        private readonly LifecycleService $lifecycle,
        private readonly NotificationService $notifications,
        private readonly AuditRecorder $audit,
    ) {}

    public function change(
        Application $application,
        ApplicationStatus $newStatus,
        string $reason,
        User $actor,
        ?string $ipAddress = null,
    ): Application {
        $this->lifecycle->changeStatus(
            $application,
            $newStatus,
            changedBy: (string) $actor->email,
            reason: $reason,
        );

        $this->audit->record(
            'status_change:'.$newStatus->value,
            actor: $actor,
            application: $application,
            ipAddress: $ipAddress,
        );

        $this->notifications->notifyStatusChange($application, $newStatus);

        return $application;
    }
}
