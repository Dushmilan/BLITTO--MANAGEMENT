<?php

use App\Domain\Filing\NipoFollowUp;
use App\Models\Application;
use App\Models\FilingRecord;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Carbon;

it('notifies every md and director about a stalled filing', function () {
    User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    User::factory()->create(['email' => 'plain@pdn.ac.lk']);

    $application = Application::query()->create([
        'title' => 'Stalled',
        'status' => 'FILED',
        'application_number' => 'LK/PAT/2026/7',
    ]);

    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => Carbon::parse('2026-09-01 08:00:00'),
        'filed_by' => 'md@pdn.ac.lk',
    ]);

    expect(app(NipoFollowUp::class)->remind())->toBe(2);

    $rows = Notification::query()->orderBy('recipient_email')->get();

    expect($rows->pluck('recipient_email')->all())->toBe(['director@pdn.ac.lk', 'md@pdn.ac.lk'])
        ->and($rows->pluck('kind')->unique()->all())->toBe(['nipo_follow_up'])
        ->and($rows->first()->subject)->toBe('NIPO Follow-up Required: Stalled')
        ->and($rows->first()->body)->toBe('Patent Stalled (LK/PAT/2026/7) was filed on 2026-09-01 but has not yet been acknowledged by NIPO. Please contact NIPO for follow-up.');
});

it('ignores acknowledged filings and applications that left FILED', function () {
    User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);

    $acknowledged = Application::query()->create(['title' => 'Acked', 'status' => 'FILED']);
    FilingRecord::query()->create([
        'application_id' => $acknowledged->id,
        'filed_date' => Carbon::parse('2026-09-01'),
        'filed_by' => 'md@pdn.ac.lk',
        'nipo_acknowledged_at' => Carbon::parse('2026-09-05'),
    ]);

    $moved = Application::query()->create(['title' => 'Moved', 'status' => 'EXAMINATION']);
    FilingRecord::query()->create([
        'application_id' => $moved->id,
        'filed_date' => Carbon::parse('2026-09-01'),
        'filed_by' => 'md@pdn.ac.lk',
    ]);

    expect(app(NipoFollowUp::class)->remind())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
});

it('falls back to the first eight id characters when there is no application number', function () {
    User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Numberless', 'status' => 'FILED']);
    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => Carbon::parse('2026-09-01'),
        'filed_by' => 'md@pdn.ac.lk',
    ]);

    app(NipoFollowUp::class)->remind();

    expect(Notification::query()->sole()->body)
        ->toContain('('.substr($application->id, 0, 8).')');
});

it('still reminds when the filing record has no filed date', function () {
    User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Undated', 'status' => 'FILED']);
    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => null,
        'filed_by' => '',
    ]);

    expect(app(NipoFollowUp::class)->remind())->toBe(1)
        ->and(Notification::query()->sole()->body)->toContain('was filed on an unrecorded date');
});

it('does nothing when no staff accounts exist', function () {
    $application = Application::query()->create(['title' => 'Stalled', 'status' => 'FILED']);
    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => Carbon::parse('2026-09-01'),
        'filed_by' => 'md@pdn.ac.lk',
    ]);

    expect(app(NipoFollowUp::class)->remind())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
});

it('re-notifies on every run until the filing is acknowledged', function () {
    User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Stubborn', 'status' => 'FILED']);
    FilingRecord::query()->create([
        'application_id' => $application->id,
        'filed_date' => Carbon::parse('2026-09-01'),
        'filed_by' => 'md@pdn.ac.lk',
    ]);

    expect(app(NipoFollowUp::class)->remind())->toBe(1)
        ->and(app(NipoFollowUp::class)->remind())->toBe(1)
        ->and(Notification::query()->count())->toBe(2);
});
