<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Filing\FilingWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller for the filing workflow. Rules (transition table,
 * defect-sheet caps, notifications, audit) live in App\Domain\Filing.
 */
final class FilingController extends Controller
{
    public function __construct(
        private readonly FilingWorkflowService $filing,
        private readonly ApplicationIntakeService $intake,
    ) {}

    public function file(Request $request, string $applicationId): JsonResponse
    {
        return response()->json($this->filing->markFiled(
            $this->intake->findOrFail($applicationId),
            $request->user(),
        ));
    }

    public function acknowledge(Request $request, string $applicationId): JsonResponse
    {
        return response()->json($this->filing->acknowledgeNipo(
            $this->intake->findOrFail($applicationId),
            $request->user(),
        ));
    }

    public function storeDefectSheet(Request $request, string $applicationId): JsonResponse
    {
        $validated = $request->validate([
            'sheet_number' => ['required', 'integer'],
            'description' => ['required', 'string'],
        ]);

        return response()->json($this->filing->recordDefectSheet(
            $this->intake->findOrFail($applicationId),
            (int) $validated['sheet_number'],
            (string) $validated['description'],
            $request->user(),
        ));
    }

    public function defectSheets(string $applicationId): JsonResponse
    {
        return response()->json($this->filing->defectSheets($this->intake->findOrFail($applicationId)));
    }

    public function grant(Request $request, string $applicationId): JsonResponse
    {
        $validated = $request->validate([
            'patent_number' => ['required', 'string', 'max:255'],
        ]);

        $application = $this->filing->markGranted(
            $this->intake->findOrFail($applicationId),
            (string) $validated['patent_number'],
            $request->user(),
        );

        $payload = $application->toArray();

        if (($warning = $this->filing->grantWarning($application)) !== null) {
            $payload['warning'] = $warning;
        }

        return response()->json($payload);
    }

    public function reject(Request $request, string $applicationId): JsonResponse
    {
        return response()->json($this->filing->markRejected(
            $this->intake->findOrFail($applicationId),
            $request->user(),
        ));
    }

    public function status(string $applicationId): JsonResponse
    {
        $record = $this->filing->filingRecord($this->intake->findOrFail($applicationId));

        if ($record === null) {
            return response()->json(['message' => 'No filing record found'], 404);
        }

        return response()->json($record);
    }
}
