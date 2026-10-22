<?php

namespace App\Support;

use App\Models\Application;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

/**
 * Presentation only: search, status filter and paging over a collection the
 * domain already scoped (spec §8.2.2). No business rule lives here.
 */
final class PatentTable
{
    public const PER_PAGE = 15;

    /**
     * @param  Collection<int, Application>  $applications
     * @return Collection<int, Application>
     */
    public static function filter(Collection $applications, ?string $query, ?string $status): Collection
    {
        $needle = mb_strtolower(trim((string) $query));
        $status = ($status === null || $status === 'all' || $status === '') ? null : strtoupper($status);

        return $applications
            ->when($needle !== '', fn (Collection $rows): Collection => $rows->filter(
                fn (Application $application): bool => str_contains(
                    mb_strtolower(implode(' ', array_filter([
                        $application->title,
                        $application->inventor_name,
                        $application->inventor_email,
                        $application->technology_area,
                        $application->application_number,
                    ]))),
                    $needle,
                ),
            ))
            ->when($status !== null, fn (Collection $rows): Collection => $rows->filter(
                fn (Application $application): bool => $application->status === $status,
            ))
            ->sortByDesc('created_at')
            ->values();
    }

    /**
     * @param  Collection<int, Application>  $rows
     */
    public static function paginate(Collection $rows, int $page, string $path): LengthAwarePaginator
    {
        $page = max(1, $page);

        return new Paginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $path],
        );
    }
}
