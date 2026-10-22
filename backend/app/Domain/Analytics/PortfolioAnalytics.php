<?php

namespace App\Domain\Analytics;

use App\Domain\Docketing\DeadlineStatus;
use App\Domain\Docketing\DeadlineType;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Models\Application;
use App\Models\Deadline;
use Illuminate\Support\Collection;

/**
 * Portfolio reporting — port of pre-laravel-final-state
 * `app/modules/portfolio_analytics/local/__init__.py`, with two deliberate
 * fixes: maps are emitted in enum declaration order (stable JSON) and
 * `by_type` counts only OPEN deadlines (the reference counted every deadline,
 * so `open_deadlines` and `by_type` disagreed).
 */
final class PortfolioAnalytics
{
    /**
     * @return array{total_applications: int, by_status: array<string, int>}
     */
    public function portfolioSummary(): array
    {
        $counts = Application::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total_applications' => Application::query()->count(),
            'by_status' => $this->orderedCounts(ApplicationStatus::cases(), $counts),
        ];
    }

    /**
     * @return array{open_deadlines: int, by_type: array<string, int>}
     */
    public function deadlineReport(): array
    {
        $open = Deadline::query()->where('status', DeadlineStatus::OPEN->value);

        $counts = (clone $open)
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        return [
            'open_deadlines' => (clone $open)->count(),
            'by_type' => $this->orderedCounts(DeadlineType::cases(), $counts),
        ];
    }

    /**
     * @param  array<int, \UnitEnum&object{value: string}>  $cases
     * @param  Collection<string, int|string>  $counts
     * @return array<string, int>
     */
    private function orderedCounts(array $cases, $counts): array
    {
        $ordered = [];

        foreach ($cases as $case) {
            $count = (int) ($counts[$case->value] ?? 0);

            if ($count > 0) {
                $ordered[$case->value] = $count;
            }
        }

        return $ordered;
    }
}
