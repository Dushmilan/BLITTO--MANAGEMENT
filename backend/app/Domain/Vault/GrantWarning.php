<?php

namespace App\Domain\Vault;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\User;

/**
 * The grant-without-document obligation, shared by both paths that can grant a
 * patent (the filing workflow and a generic status change): warn the inventor
 * that the document is pending, warn the admin inbox, and audit it.
 */
final class GrantWarning
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly NotificationService $notifications,
        private readonly AuditRecorder $audit,
    ) {}

    public function hasDocument(Application $application): bool
    {
        return $this->documents->hasDocuments($application);
    }

    public function warning(Application $application): ?string
    {
        if (ApplicationStatus::from($application->status) !== ApplicationStatus::GRANTED) {
            return null;
        }

        if ($this->hasDocument($application)) {
            return null;
        }

        return "Patent {$application->id} was granted but has no document yet; the inventor cannot download it until one is uploaded.";
    }

    public function announce(Application $application, User $actor, ?string $ipAddress = null): ?string
    {
        $warning = $this->warning($application);

        if ($warning === null) {
            return null;
        }

        $this->notifications->send(
            (string) (config('blitto.bootstrap_admin_email') ?: 'md@pdn.ac.lk'),
            "Patent {$application->id} granted without a document",
            $warning,
            'grant_without_document',
        );

        $this->audit->record(
            'grant_without_document',
            actor: $actor,
            application: $application,
            ipAddress: $ipAddress,
        );

        return $warning;
    }
}
