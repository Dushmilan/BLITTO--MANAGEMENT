<?php

namespace App\Domain\Notification;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read side of the notification log: what the addressed user may see. There is
 * no read/unread state (no schema change), so this is a plain projection of
 * `recipient_email = the user's email`, newest first (spec §9).
 */
final class NotificationFeed
{
    public const MAX_ROWS = 100;

    /**
     * @return Collection<int, Notification>
     */
    public function forUser(User $user): Collection
    {
        return Notification::query()
            ->where('recipient_email', $user->email)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ROWS)
            ->get();
    }

    public function countFor(User $user): int
    {
        return Notification::query()->where('recipient_email', $user->email)->count();
    }
}
