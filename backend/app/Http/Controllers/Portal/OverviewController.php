<?php

namespace App\Http\Controllers\Portal;

use App\Domain\ApplicationIntake\ApplicationIntakeService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class OverviewController extends Controller
{
    public function __construct(private readonly ApplicationIntakeService $intake) {}

    public function __invoke(Request $request): View
    {
        $viewer = $request->user();
        $applications = $this->intake->listVisibleTo($viewer)->sortByDesc('created_at');

        return view('portal.overview', [
            'viewer' => $viewer,
            'applications' => $applications,
            'recent' => $applications->take(3),
        ]);
    }
}
