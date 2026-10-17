<?php

namespace App\Http\Controllers;

use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\StatusChangeService;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Thin controller: parses input, delegates to the lifecycle domain, returns 204.
 * Domain rules (transition table, notification, audit) live in App\Domain\Lifecycle.
 */
class ApplicationStatusController extends Controller
{
    public function __construct(private readonly StatusChangeService $statusChange) {}

    public function store(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'new_status' => ['required', Rule::enum(ApplicationStatus::class)],
            'reason' => ['required', 'string', 'min:1', 'not_regex:/^\s*$/'],
        ]);

        $this->statusChange->change(
            $application,
            ApplicationStatus::from($validated['new_status']),
            reason: (string) $validated['reason'],
            actor: $request->user(),
            ipAddress: $request->ip(),
        );

        return response()->json(null, 204);
    }
}
