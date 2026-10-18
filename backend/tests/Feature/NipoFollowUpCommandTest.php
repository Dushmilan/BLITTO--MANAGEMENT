<?php

use App\Console\Commands\NipoFollowUpCommand;
use App\Models\Application;
use App\Models\FilingRecord;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

it('reminds staff through the artisan command', function () {
    User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Stalled', 'status' => 'FILED']);
    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => Carbon::parse('2026-09-01'),
        'filed_by' => 'md@pdn.ac.lk',
    ]);

    $this->artisan('nipo:follow-up')
        ->expectsOutputToContain('1')
        ->assertSuccessful();

    expect(Notification::query()->where('kind', 'nipo_follow_up')->count())->toBe(1);
});

it('derives the schedule expression from the configured interval', function () {
    expect(NipoFollowUpCommand::expressionFor(24))->toBe('0 */24 * * *')
        ->and(NipoFollowUpCommand::expressionFor(6))->toBe('0 */6 * * *')
        ->and(NipoFollowUpCommand::expressionFor(0))->toBe('0 */1 * * *');
});

it('registers the command in the scheduler without overlap', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'nipo:follow-up'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 */24 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
