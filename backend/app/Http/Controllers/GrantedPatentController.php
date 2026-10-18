<?php

namespace App\Http\Controllers;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Vault\GrantedPatentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin controller: the GRANTED/ownership rules and the ZIP assembly live in
 * App\Domain\Vault\GrantedPatentService.
 */
final class GrantedPatentController extends Controller
{
    public function __construct(
        private readonly GrantedPatentService $granted,
        private readonly ApplicationIntakeService $intake,
    ) {}

    public function download(Request $request, string $applicationId): Response
    {
        $download = $this->granted->downloadPayload(
            $this->intake->findOrFail($applicationId),
            $request->user(),
            $request->ip(),
        );

        $headers = [
            'Content-Type' => $download->contentType,
            'Content-Disposition' => 'attachment; filename="'.$download->downloadName.'"',
            'X-Downloaded-At' => now()->utc()->toIso8601String(),
        ];

        if ($download->zipPath !== null) {
            $zipped = response()->download($download->zipPath, $download->downloadName, $headers);

            // Symfony's download() rewrites the disposition unquoted; keep the
            // same quoted form the single-file branch uses.
            $zipped->headers->set(
                'Content-Disposition',
                'attachment; filename="'.$download->downloadName.'"',
            );

            return $zipped->deleteFileAfterSend();
        }

        return response($download->bytes, 200, $headers);
    }
}
