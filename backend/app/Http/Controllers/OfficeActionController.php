<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Prosecution\OfficeActionKind;
use App\Domain\Prosecution\ProsecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Thin controller for prosecution. Auto-docketing rules live in
 * App\Domain\Prosecution.
 */
final class OfficeActionController extends Controller
{
    public function __construct(
        private readonly ProsecutionService $prosecution,
        private readonly ApplicationIntakeService $intake,
    ) {}

    public function store(Request $request, string $applicationId): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::enum(OfficeActionKind::class)],
            'body' => ['required', 'string'],
        ]);

        return response()->json($this->prosecution->receiveOfficeAction(
            $this->intake->findOrFail($applicationId),
            OfficeActionKind::from($validated['kind']),
            (string) $validated['body'],
            $request->user(),
            $request->ip(),
        ));
    }
}
