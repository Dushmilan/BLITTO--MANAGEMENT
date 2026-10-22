<?php

namespace App\Domain\Lifecycle;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Notification\NotificationService;
use App\Domain\Vault\GrantWarning;
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
        private readonly GrantWarning $grantWarning,
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

        $documentAvailable = $newStatus === ApplicationStatus::GRANTED
            ? $this->grantWarning->hasDocument($application)
            : true;

        $this->notifications->notifyStatusChange($application, $newStatus, $documentAvailable);

        if ($newStatus === ApplicationStatus::GRANTED) {
            $this->grantWarning->announce($application, $actor, $ipAddress);
        }

        return $application;
    }
}
