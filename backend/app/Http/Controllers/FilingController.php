<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Filing\FilingWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    public function file(Request $request, string $applicationId): JsonResponse|RedirectResponse
    {
        $application = $this->filing->markFiled(
            $this->intake->findOrFail($applicationId),
            $request->user(),
            $request->ip(),
        );

        if ($request->expectsJson()) {
            return response()->json($application);
        }

        return back()->with('status', 'Filing recorded.');
    }

    public function acknowledge(Request $request, string $applicationId): JsonResponse|RedirectResponse
    {
        $application = $this->filing->acknowledgeNipo(
            $this->intake->findOrFail($applicationId),
            $request->user(),
            $request->ip(),
        );

        if ($request->expectsJson()) {
            return response()->json($application);
        }

        return back()->with('status', 'NIPO acknowledgement recorded.');
    }

    public function storeDefectSheet(Request $request, string $applicationId): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'sheet_number' => ['required', 'integer'],
            'description' => ['required', 'string'],
        ]);

        $sheet = $this->filing->recordDefectSheet(
            $this->intake->findOrFail($applicationId),
            (int) $validated['sheet_number'],
            (string) $validated['description'],
            $request->user(),
            $request->ip(),
        );

        if ($request->expectsJson()) {
            return response()->json($sheet);
        }

        return back()->with('status', 'Defect sheet recorded.');
    }

    public function defectSheets(string $applicationId): JsonResponse
    {
        return response()->json($this->filing->defectSheets($this->intake->findOrFail($applicationId)));
    }

    public function grant(Request $request, string $applicationId): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'patent_number' => ['required', 'string', 'max:255'],
        ]);

        $application = $this->filing->markGranted(
            $this->intake->findOrFail($applicationId),
            (string) $validated['patent_number'],
            $request->user(),
            $request->ip(),
        );

        $warning = $this->filing->grantWarning($application);

        if ($request->expectsJson()) {
            $payload = $application->toArray();

            if ($warning !== null) {
                $payload['warning'] = $warning;
            }

            return response()->json($payload);
        }

        return back()
            ->with('status', 'Patent granted as '.$validated['patent_number'].'.')
            ->with('warning', $warning);
    }

    public function reject(Request $request, string $applicationId): JsonResponse|RedirectResponse
    {
        $application = $this->filing->markRejected(
            $this->intake->findOrFail($applicationId),
            $request->user(),
            $request->ip(),
        );

        if ($request->expectsJson()) {
            return response()->json($application);
        }

        return back()->with('status', 'Patent rejected.');
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
