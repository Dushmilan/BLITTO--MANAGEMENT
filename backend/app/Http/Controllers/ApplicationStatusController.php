<?php

namespace App\Http\Controllers;

use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\LifecycleService;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller: parses input, delegates to LifecycleService, returns 204.
 * Domain rules (transition table) live in App\Domain\Lifecycle.
 */
class ApplicationStatusController extends Controller
{
    public function __construct(private readonly LifecycleService $lifecycle) {}

    public function store(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'new_status' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:1', 'not_regex:/^\s*$/'],
        ]);

        $this->lifecycle->changeStatus(
            $application,
            ApplicationStatus::from($validated['new_status']),
            changedBy: (string) $request->user()->email,
            reason: $validated['reason'],
        );

        return response()->json(null, 204);
    }
}
