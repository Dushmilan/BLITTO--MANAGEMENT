<?php

namespace App\Http\Controllers;

use App\Domain\Analytics\PortfolioAnalytics;
use Illuminate\Http\JsonResponse;

/**
 * Read-only portfolio reporting. Thin: every number comes from the domain
 * module; the empty-map cast keeps `{}` in the JSON instead of `[]`.
 */
final class AnalyticsController extends Controller
{
    public function __construct(private readonly PortfolioAnalytics $analytics) {}

    public function portfolio(): JsonResponse
    {
        $summary = $this->analytics->portfolioSummary();
        $summary['by_status'] = (object) $summary['by_status'];

        return response()->json($summary);
    }

    public function deadlines(): JsonResponse
    {
        $report = $this->analytics->deadlineReport();
        $report['by_type'] = (object) $report['by_type'];

        return response()->json($report);
    }
}
