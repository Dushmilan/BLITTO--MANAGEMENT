<?php

namespace App\Domain\Docketing;

use App\Domain\Audit\AuditRecorder;
use App\Models\Application;
use App\Models\Deadline;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Docketing domain rules — the sole authority on deadlines (CONTEXT.md
 * invariant 4) and deadline integrity (invariant 1). Port of
 * pre-laravel-final-state `app/modules/docketing/local/`.
 */
class DocketingService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @throws IllegalDeadlineChange when the deadline is already MISSED
     */
    public function markMet(Deadline $deadline): Deadline
    {
        if (DeadlineStatus::from($deadline->status) === DeadlineStatus::MISSED) {
            throw new IllegalDeadlineChange('Missed deadline is final; cannot be marked met');
        }

        $deadline->status = DeadlineStatus::MET->value;
        $deadline->save();

        return $deadline;
    }

    public function addDeadline(
        Application $application,
        DeadlineType $type,
        CarbonInterface $dueDate,
        User $actor,
        ?string $ipAddress = null,
    ): Deadline {
        $deadline = Deadline::query()->create([
            'application_id' => $application->id,
            'type' => $type->value,
            'due_date' => $dueDate,
            'status' => DeadlineStatus::OPEN->value,
        ]);

        $this->audit->record(
            'add_deadline:'.$type->value,
            actor: $actor,
            application: $application,
            ipAddress: $ipAddress,
        );

        return $deadline;
    }

    /**
     * @return Collection<int, Deadline>
     */
    public function listDeadlines(?string $applicationId = null): Collection
    {
        return Deadline::query()
            ->when($applicationId !== null, fn ($query) => $query->where('application_id', $applicationId))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
