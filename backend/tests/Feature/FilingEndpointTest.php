<?php

use App\Models\Application;
use App\Models\Deadline;
use App\Models\FilingRecord;
use App\Models\Inventor;
use App\Models\User;

function filingApplication(): Application
{
    return Application::query()->create(['title' => 'Flow Test Invention', 'inventor_email' => 'ada@pdn.ac.lk']);
}

it('drives the filing chain from file to grant', function () {
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);
    $application = filingApplication();
    Inventor::query()->create([
        'application_id' => $application->id,
        'inventor_name' => 'Ada',
        'inventor_email' => 'ada@pdn.ac.lk',
    ]);

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/file")->assertOk()->assertJsonPath('status', 'FILED');

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/acknowledge")
        ->assertOk()->assertJsonPath('status', 'ACKNOWLEDGED');

    $this->actingAs($md)->getJson("/admin/filing/{$application->id}/status")
        ->assertOk()
        ->assertJsonPath('nipo_acknowledged_by', 'md@pdn.ac.lk')
        ->assertJsonPath('application_id', $application->id);

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/defect-sheets?sheet_number=1&description=Missing%20signature")
        ->assertOk()->assertJsonPath('sheet_number', 1);

    $this->actingAs($md)->getJson("/admin/filing/{$application->id}/defect-sheets")
        ->assertOk()->assertJsonPath('0.description', 'Missing signature');

    // DEFECT_SHEET_1 may not jump straight to GRANTED; re-acknowledge first.
    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/acknowledge")
        ->assertOk()->assertJsonPath('status', 'ACKNOWLEDGED');

    $this->actingAs($md)->postJson("/admin/filing/{$application->id}/grant?patent_number=LK/PAT/2026/888")
        ->assertOk()->assertJsonPath('status', 'GRANTED');

    $record = FilingRecord::query()->sole();

    expect($record->patent_number)->toBe('LK/PAT/2026/888')
        ->and($record->defect_sheet_count)->toBe(1)
        ->and($record->granted_by)->toBe('md@pdn.ac.lk');
});

it('rejects a filed application', function () {
    $director = User::factory()->director()->create();
    $application = filingApplication();

    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/file")->assertOk();
    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/reject")
        ->assertOk()->assertJsonPath('status', 'REJECTED');

    expect(FilingRecord::query()->sole()->rejected_at)->not->toBeNull();
});

it('is idempotent when filed twice', function () {
    $director = User::factory()->director()->create();
    $application = filingApplication();

    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/file")->assertOk();
    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/file")->assertOk();

    expect(FilingRecord::query()->count())->toBe(1);
});

it('returns 404 for filing steps on an unknown application', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson('/admin/filing/missing-id/file')
        ->assertNotFound()->assertJsonPath('message', 'Application missing-id not found');
    $this->actingAs($director)->getJson('/admin/filing/missing-id/status')
        ->assertNotFound()->assertJsonPath('message', 'Application missing-id not found');
});

it('returns 404 when acknowledging an application that was never filed', function () {
    $director = User::factory()->director()->create();
    $application = filingApplication();

    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/acknowledge")
        ->assertNotFound();
});

it('returns 404 when no filing record exists yet', function () {
    $director = User::factory()->director()->create();
    $application = filingApplication();

    $this->actingAs($director)->getJson("/admin/filing/{$application->id}/status")
        ->assertNotFound()
        ->assertJsonPath('message', 'No filing record found');
});

it('rejects illegal transitions and bad defect sheets with 422', function () {
    $director = User::factory()->director()->create();
    $application = filingApplication();

    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/grant?patent_number=X")
        ->assertStatus(422);

    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/file")->assertOk();

    $this->actingAs($director)
        ->postJson("/admin/filing/{$application->id}/defect-sheets?sheet_number=4&description=x")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Invalid defect sheet number: 4 (must be 1-3)');

    $this->actingAs($director)
        ->postJson("/admin/filing/{$application->id}/defect-sheets?sheet_number=1&description=x")
        ->assertOk();

    $this->actingAs($director)
        ->postJson("/admin/filing/{$application->id}/defect-sheets?sheet_number=1&description=y")
        ->assertStatus(422)
        ->assertJsonPath('message', "Defect sheet #1 already exists for {$application->id}");

    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/grant")
        ->assertStatus(422)->assertJsonValidationErrors('patent_number');

    expect($application->refresh()->status)->toBe('DEFECT_SHEET_1');
    expect(Deadline::query()->count())->toBe(0);
});

it('gates every filing step to staff', function () {
    $application = filingApplication();
    $uri = "/admin/filing/{$application->id}/file";

    $this->postJson($uri)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($uri)->assertForbidden();
});
