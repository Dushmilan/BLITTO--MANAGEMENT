<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\User;

it('records the client IP on the file, acknowledge and grant audit rows', function () {
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Filed', 'inventor_email' => 'ada@pdn.ac.lk']);

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/file")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/acknowledge")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/grant?patent_number=LK/PAT/2026/9")->assertOk();

    $rows = AuditLog::query()
        ->whereIn('action', ['filing:mark_filed', 'filing:acknowledge_nipo', 'filing:granted'])
        ->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->whereNull('ip_address'))->toHaveCount(0)
        ->and($rows->pluck('ip_address')->unique()->all())->toBe(['127.0.0.1']);
});

it('records the client IP on the defect-sheet and reject audit rows', function () {
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);

    $defective = Application::query()->create(['title' => 'Defective', 'inventor_email' => 'ada@pdn.ac.lk']);
    $rejected = Application::query()->create(['title' => 'Rejected', 'inventor_email' => 'bob@pdn.ac.lk']);

    $this->actingAs($md)->postJson("/admin/filing/{$defective->id}/file")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$defective->id}/defect-sheets", [
        'sheet_number' => 1,
        'description' => 'Claim 1 lacks novelty',
    ])->assertOk();

    $this->actingAs($md)->postJson("/admin/filing/{$rejected->id}/file")->assertOk();
    $this->actingAs($md)->postJson("/admin/filing/{$rejected->id}/reject")->assertOk();

    $rows = AuditLog::query()->whereIn('action', ['filing:defect_sheet', 'filing:rejected'])->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->whereNull('ip_address'))->toHaveCount(0)
        ->and($rows->pluck('ip_address')->unique()->all())->toBe(['127.0.0.1']);
});
