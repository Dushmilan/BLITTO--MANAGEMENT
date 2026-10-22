<?php

namespace App\Http\Controllers\Admin;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Filing\FilingWorkflowService;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Support\PatentTable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Thin: the domain scopes what the viewer may see, the presenter filters and
 * pages it.
 */
final class PatentController extends Controller
{
    public function __construct(
        private readonly ApplicationIntakeService $intake,
        private readonly FilingWorkflowService $filing,
    ) {}

    public function index(Request $request): View
    {
        $rows = PatentTable::filter(
            $this->intake->listVisibleTo($request->user()),
            $request->query('q'),
            $request->query('status'),
        );

        return view('admin.patents.index', [
            'viewer' => $request->user(),
            'applications' => PatentTable::paginate($rows, (int) $request->query('page', 1), '/admin/patents'),
            'total' => $rows->count(),
            'query' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', 'all'),
            'statuses' => ApplicationStatus::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.patents.create', ['viewer' => $request->user()]);
    }

    public function show(Request $request, string $applicationId): View
    {
        $application = $this->intake->findOrFail($applicationId);
        $record = $this->filing->filingRecord($application);

        return view('admin.patents.show', [
            'viewer' => $request->user(),
            'application' => $application,
            'history' => $this->intake->history($application),
            'filingRecord' => $record,
            'defectSheets' => $this->filing->defectSheets($application),
            'documents' => collect(),
            'warning' => $this->filing->grantWarning($application),
            'statuses' => ApplicationStatus::cases(),
        ]);
    }
}
