<?php

namespace App\Domain\Audit;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Audit trail (CONTEXT.md invariant 5) — port of pre-laravel-final-state
 * `app/modules/audit/interface.py`. Every state change is traceable to an
 * actor, an application and a timestamp.
 */
final class AuditRecorder
{
    public function record(
        string $action,
        ?User $actor = null,
        ?Application $application = null,
        ?string $ipAddress = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'action' => $action,
            'user_id' => $actor?->id,
            'application_id' => $application?->id,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public function forApplication(Application $application): Collection
    {
        return AuditLog::query()
            ->where('application_id', $application->id)
            ->orderBy('timestamp')
            ->get();
    }
}
