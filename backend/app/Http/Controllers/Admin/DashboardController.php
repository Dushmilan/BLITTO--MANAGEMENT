<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Analytics\PortfolioAnalytics;
use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Domain\Authorization\Ability;
use App\Domain\Authorization\Role;
use App\Domain\Vault\DocumentService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Thin: every number comes from an existing domain module.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly PortfolioAnalytics $analytics,
        private readonly ApplicationIntakeService $intake,
        private readonly DocumentService $documents,
    ) {}

    public function __invoke(Request $request): View
    {
        $viewer = $request->user();
        $summary = $this->analytics->portfolioSummary();

        return view('admin.dashboard', [
            'viewer' => $viewer,
            'summary' => $summary,
            'deadlines' => $this->analytics->deadlineReport(),
            'recent' => $this->intake->listVisibleTo($viewer)->sortByDesc('created_at')->take(5),
            'missing' => $this->documents->missingGrantedDocuments(),
            'users' => Role::from($viewer->role)->can(Ability::MANAGE_USERS)
                ? User::query()->orderBy('email')->get()
                : null,
        ]);
    }
}
