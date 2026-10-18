<?php

namespace App\Domain\Filing;

use App\Domain\Authorization\Role;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Notification\NotificationService;
use App\Models\Application;
use App\Models\FilingRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * NIPO follow-up (spec §8): after filing, the reference nudged every MD and
 * director while NIPO stayed silent. Idempotency is deliberately absent —
 * a daily nudge until someone acknowledges is the point.
 */
final class NipoFollowUp
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** @return Collection<int, Application> */
    public function stalled(): Collection
    {
        $unacknowledged = FilingRecord::query()
            ->whereNull('nipo_acknowledged_at')
            ->pluck('application_id');

        return Application::query()
            ->where('status', ApplicationStatus::FILED->value)
            ->whereIn('id', $unacknowledged)
            ->orderBy('id')
            ->get();
    }

    public function remind(): int
    {
        $staff = User::query()
            ->whereIn('role', [Role::MD->value, Role::DIRECTOR->value])
            ->orderBy('email')
            ->get();

        if ($staff->isEmpty()) {
            return 0;
        }

        $sent = 0;

        foreach ($this->stalled() as $application) {
            $body = $this->body($application);

            foreach ($staff as $member) {
                $this->notifications->send(
                    $member->email,
                    "NIPO Follow-up Required: {$application->title}",
                    $body,
                    'nipo_follow_up',
                );

                $sent++;
            }
        }

        return $sent;
    }

    private function body(Application $application): string
    {
        $record = FilingRecord::query()->where('application_id', $application->id)->first();
        $filed = $record?->filed_date;

        // filed_date is a nullable column; a filing record without one must not
        // silence the reminder (Review Focus 2).
        $filedOn = $filed === null || $filed === ''
            ? 'an unrecorded date'
            : Carbon::parse($filed)->format('Y-m-d');

        $reference = $application->application_number ?: substr($application->id, 0, 8);

        return "Patent {$application->title} ({$reference}) was filed on {$filedOn} but has not yet been acknowledged by NIPO. Please contact NIPO for follow-up.";
    }
}
