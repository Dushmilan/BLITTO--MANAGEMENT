<?php

namespace App\Http\Controllers;

use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\LifecycleService;
use App\Models\Application;
use Illuminate\Http\JsonResponse;

/**
 * Thin controller: parses input, delegates to LifecycleService, returns 204.
 * Domain rules (transition table) live in App\Domain\Lifecycle.
 */
class ApplicationStatusController extends Controller
{
    public function __construct(private readonly LifecycleService $lifecycle) {}

    public function store(Application $application): JsonResponse
    {
        $this->lifecycle->changeStatus(
            $application,
            ApplicationStatus::from(request('new_status')),
            changedBy: request('changed_by', 'system'),
            reason: request('reason', ''),
        );

        return response()->json(null, 204);
    }
}
