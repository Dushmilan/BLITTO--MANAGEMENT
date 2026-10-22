<?php

namespace App\Http\Controllers\Portal;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Authorization\ApplicationVisibility;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Vault\DocumentService;
use App\Domain\Vault\GrantedPatentService;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PatentController extends Controller
{
    public function __construct(
        private readonly ApplicationIntakeService $intake,
        private readonly DocumentService $documents,
        private readonly GrantedPatentService $granted,
    ) {}

    public function index(Request $request): View
    {
        return view('portal.patents.index', [
            'viewer' => $request->user(),
            'applications' => $this->intake->listVisibleTo($request->user())->sortByDesc('created_at')->values(),
        ]);
    }

    public function show(Request $request, string $applicationId): View
    {
        $viewer = $request->user();
        $application = $this->findVisibleOrFail($viewer, $applicationId);
        $granted = $application->status === ApplicationStatus::GRANTED->value;
        $hasDocument = $this->documents->hasDocuments($application);
        $openRequest = $granted && ! $hasDocument
            && $this->granted->hasOpenRequest($application, $viewer);

        return view('portal.patents.show', [
            'viewer' => $viewer,
            'application' => $application,
            'history' => $this->intake->history($application),
            'documents' => $this->documents->listFor($application),
            'granted' => $granted,
            'hasDocument' => $hasDocument,
            'openRequest' => $openRequest,
            'canRequest' => $granted && ! $hasDocument && ! $openRequest,
        ]);
    }

    private function findVisibleOrFail(User $viewer, string $applicationId): Application
    {
        // Confidentiality (invariant 6): a miss is a 404, so the page never
        // confirms that someone else's id exists (spec §5.2).
        return ApplicationVisibility::visibleTo(Application::query(), $viewer)
            ->whereKey($applicationId)
            ->first() ?? abort(404);
    }
}
