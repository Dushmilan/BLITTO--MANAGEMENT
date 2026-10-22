<?php

namespace App\Http\Controllers\Portal;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Authorization\ApplicationVisibility;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PatentController extends Controller
{
    public function __construct(private readonly ApplicationIntakeService $intake) {}

    public function index(Request $request): View
    {
        return view('portal.patents.index', [
            'viewer' => $request->user(),
            'applications' => $this->intake->listVisibleTo($request->user())->sortByDesc('created_at')->values(),
        ]);
    }

    public function show(Request $request, string $applicationId): View
    {
        $application = $this->findVisibleOrFail($request->user(), $applicationId);

        return view('portal.patents.show', [
            'viewer' => $request->user(),
            'application' => $application,
            'history' => $this->intake->history($application),
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
