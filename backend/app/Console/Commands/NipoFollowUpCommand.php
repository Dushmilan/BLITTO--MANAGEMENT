<?php

namespace App\Console\Commands;

use App\Domain\Filing\NipoFollowUp;
use Illuminate\Console\Command;

final class NipoFollowUpCommand extends Command
{
    protected $signature = 'nipo:follow-up';

    protected $description = 'Remind staff about applications filed with NIPO but not yet acknowledged';

    /**
     * The cadence expression for a check-every-N-hours interval: 24 hours is a
     * daily run, anything smaller steps within the day.
     */
    public static function expressionFor(int $hours): string
    {
        return '0 */'.max(1, $hours).' * * *';
    }

    public function handle(NipoFollowUp $nipo): int
    {
        $sent = $nipo->remind();

        $this->info("NIPO follow-up: {$sent} reminder(s) written.");

        return self::SUCCESS;
    }
}
