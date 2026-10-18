<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Vault\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller: validates the upload envelope and delegates to
 * App\Domain\Vault. Storage rules live in the domain, never here.
 */
final class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly ApplicationIntakeService $intake,
    ) {}

    public function store(Request $request, string $applicationId): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
        ]);

        return response()->json($this->documents->store(
            $this->intake->findOrFail($applicationId),
            $validated['file'],
            $request->user(),
            $request->ip(),
        ));
    }

    public function index(string $applicationId): JsonResponse
    {
        return response()->json($this->documents->listFor($this->intake->findOrFail($applicationId)));
    }
}
