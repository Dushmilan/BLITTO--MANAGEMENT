<?php

namespace App\Domain\Docketing;

use App\Models\Deadline;

/**
 * Docketing domain rules — deadline integrity (CONTEXT.md invariant 1).
 *
 * A missed deadline is final: it cannot be softened back to MET or OPEN.
 */
class DocketingService
{
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
}
