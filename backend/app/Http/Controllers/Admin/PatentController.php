<?php

namespace App\Http\Controllers\Admin;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Filing\FilingWorkflowService;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Vault\DocumentService;
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
        private readonly DocumentService $documents,
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

        // The domain's grantWarning() describes an application that is already
        // granted. Before granting, warn from the fact that matters: there is no
        // document to hand over yet.
        $warning = $this->filing->grantWarning($application);

        if ($warning === null
            && $record?->nipo_acknowledged_at !== null
            && in_array($application->status, [ApplicationStatus::ACKNOWLEDGED->value, ApplicationStatus::EXAMINATION->value], true)
            && ! $this->documents->hasDocuments($application)) {
            $warning = 'No document is uploaded yet: granting now will notify the inventor that the document is pending, and the admin inbox will receive a warning.';
        }

        return view('admin.patents.show', [
            'viewer' => $request->user(),
            'application' => $application,
            'history' => $this->intake->history($application),
            'filingRecord' => $record,
            'defectSheets' => $this->filing->defectSheets($application),
            'documents' => $this->documents->listFor($application),
            'documentsLocked' => $record?->filed_date !== null,
            'warning' => $warning,
            'statuses' => ApplicationStatus::cases(),
        ]);
    }
}
