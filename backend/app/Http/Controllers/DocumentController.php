<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Vault\DocumentFilename;
use App\Domain\Vault\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

    public function missing(): JsonResponse
    {
        return response()->json($this->documents->missingGrantedDocuments());
    }

    public function download(string $applicationId, string $documentId): Response
    {
        $application = $this->intake->findOrFail($applicationId);
        $document = $this->documents->findForApplication($application, $documentId);

        return response($this->documents->bytesFor($document), 200, [
            'Content-Type' => DocumentFilename::mimeType($document->filename),
            'Content-Disposition' => 'attachment; filename="'.DocumentFilename::safe($document->filename).'"',
        ]);
    }

    public function destroy(Request $request, string $applicationId, string $documentId): Response
    {
        $application = $this->intake->findOrFail($applicationId);

        $this->documents->delete(
            $this->documents->findForApplication($application, $documentId),
            $request->user(),
            $request->ip(),
        );

        return response()->noContent();
    }
}
