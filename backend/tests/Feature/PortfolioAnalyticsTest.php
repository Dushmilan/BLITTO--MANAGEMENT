<?php

use App\Domain\Analytics\PortfolioAnalytics;
use App\Domain\Lifecycle\ApplicationStatus;
use App\Domain\Lifecycle\StatusChangeService;
use App\Models\Application;
use App\Models\Deadline;
use App\Models\User;
use Illuminate\Support\Carbon;

it('reports an empty portfolio', function () {
    expect(app(PortfolioAnalytics::class)->portfolioSummary())
        ->toBe(['total_applications' => 0, 'by_status' => []])
        ->and(app(PortfolioAnalytics::class)->deadlineReport())
        ->toBe(['open_deadlines' => 0, 'by_type' => []]);
});

it('counts every application by status in lifecycle order', function () {
    Application::query()->create(['title' => 'Granted']);
    Application::query()->create(['title' => 'Draft A']);
    Application::query()->create(['title' => 'Draft B']);
    Application::query()->where('title', 'Granted')->update(['status' => 'GRANTED']);

    expect(app(PortfolioAnalytics::class)->portfolioSummary())->toBe([
        'total_applications' => 3,
        'by_status' => ['DRAFT' => 2, 'GRANTED' => 1],
    ]);
});

it('counts only open deadlines by type', function () {
    $application = Application::query()->create(['title' => 'Docketed']);

    foreach ([
        ['RESPONSE', 'OPEN'],
        ['RESPONSE', 'MET'],
        ['MAINTENANCE_FEE', 'OPEN'],
        ['FILING', 'MISSED'],
    ] as [$type, $status]) {
        Deadline::query()->create([
            'application_id' => $application->id,
            'type' => $type,
            'due_date' => Carbon::now()->addDays(10),
            'status' => $status,
        ]);
    }

    expect(app(PortfolioAnalytics::class)->deadlineReport())->toBe([
        'open_deadlines' => 2,
        'by_type' => ['RESPONSE' => 1, 'MAINTENANCE_FEE' => 1],
    ]);
});

it('moves a count when an application changes status', function () {
    $application = Application::query()->create(['title' => 'Moving', 'inventor_email' => 'ada@pdn.ac.lk']);
    $director = User::factory()->director()->create();

    expect(app(PortfolioAnalytics::class)->portfolioSummary()['by_status'])->toBe(['DRAFT' => 1]);

    app(StatusChangeService::class)->change(
        $application,
        ApplicationStatus::FILED,
        'Filed receipt recorded',
        $director,
    );

    expect(app(PortfolioAnalytics::class)->portfolioSummary()['by_status'])->toBe(['FILED' => 1]);
});
