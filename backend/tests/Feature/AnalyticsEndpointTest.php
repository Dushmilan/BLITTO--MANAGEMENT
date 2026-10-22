<?php

use App\Models\Application;
use App\Models\Deadline;
use App\Models\User;
use Illuminate\Support\Carbon;

it('returns the portfolio summary for staff', function () {
    Application::query()->create(['title' => 'Draft']);
    $granted = Application::query()->create(['title' => 'Granted']);
    $granted->status = 'GRANTED';
    $granted->save();

    $this->actingAs(User::factory()->director()->create())
        ->getJson('/analytics/portfolio')
        ->assertOk()
        ->assertExactJson([
            'total_applications' => 2,
            'by_status' => ['DRAFT' => 1, 'GRANTED' => 1],
        ]);
});

it('returns the deadline report for md', function () {
    $application = Application::query()->create(['title' => 'Docketed']);
    Deadline::query()->create([
        'application_id' => $application->id,
        'type' => 'RESPONSE',
        'due_date' => Carbon::now()->addDays(5),
        'status' => 'OPEN',
    ]);

    $this->actingAs(User::factory()->md()->create())
        ->getJson('/analytics/deadlines')
        ->assertOk()
        ->assertExactJson(['open_deadlines' => 1, 'by_type' => ['RESPONSE' => 1]]);
});

it('serialises empty maps as json objects', function () {
    $portfolio = $this->actingAs(User::factory()->md()->create())
        ->getJson('/analytics/portfolio')
        ->assertOk();

    expect($portfolio->getContent())->toBe('{"total_applications":0,"by_status":{}}');

    $this->app['auth']->forgetGuards();

    $deadlines = $this->actingAs(User::factory()->director()->create())
        ->getJson('/analytics/deadlines')
        ->assertOk();

    expect($deadlines->getContent())->toBe('{"open_deadlines":0,"by_type":{}}');
});

it('refuses guests and plain users', function () {
    $this->getJson('/analytics/portfolio')->assertUnauthorized();
    $this->app['auth']->forgetGuards();

    $this->actingAs(User::factory()->create())->getJson('/analytics/portfolio')->assertForbidden();
    $this->app['auth']->forgetGuards();

    $this->actingAs(User::factory()->create())->getJson('/analytics/deadlines')->assertForbidden();
});
