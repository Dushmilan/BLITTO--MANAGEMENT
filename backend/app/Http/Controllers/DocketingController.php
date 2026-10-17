<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Docketing\DeadlineType;
use App\Domain\Docketing\DocketingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Thin controller for the docket. Deadline rules live in App\Domain\Docketing.
 */
final class DocketingController extends Controller
{
    public function __construct(
        private readonly DocketingService $docketing,
        private readonly ApplicationIntakeService $intake,
    ) {}

    public function store(Request $request, string $applicationId): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(DeadlineType::class)],
            'due_date' => ['required', 'date'],
        ]);

        $deadline = $this->docketing->addDeadline(
            $this->intake->findOrFail($applicationId),
            DeadlineType::from($validated['type']),
            Carbon::parse($validated['due_date']),
            $request->user(),
            $request->ip(),
        );

        return response()->json($deadline);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'application_id' => ['sometimes', 'string'],
        ]);

        return response()->json($this->docketing->listDeadlines($validated['application_id'] ?? null));
    }
}
