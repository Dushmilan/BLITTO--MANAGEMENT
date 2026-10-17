<?php

use App\Models\Application;
use App\Models\User;

it('gates every L3 endpoint against guests and plain users', function () {
    $application = Application::query()->create(['title' => 'Matrix']);
    $user = User::factory()->create(['email' => 'user-a@pdn.ac.lk']);

    // [method, uri, body]
    $endpoints = [
        ['POST', '/applications', ['title' => 'T', 'inventor_name' => 'A', 'inventor_email' => 'a@pdn.ac.lk']],
        ['PATCH', "/applications/{$application->id}", ['title' => 'T']],
        ['GET', "/applications/{$application->id}/history", []],
        ['POST', "/applications/{$application->id}/deadlines?type=FILING&due_date=2027-01-01T00:00:00Z", []],
        ['GET', '/deadlines', []],
        ['POST', "/applications/{$application->id}/office-actions?kind=REJECTION&body=x", []],
        ['POST', "/admin/filing/{$application->id}/file", []],
        ['POST', "/admin/filing/{$application->id}/acknowledge", []],
        ['POST', "/admin/filing/{$application->id}/defect-sheets?sheet_number=1&description=x", []],
        ['GET', "/admin/filing/{$application->id}/defect-sheets", []],
        ['POST', "/admin/filing/{$application->id}/grant?patent_number=X", []],
        ['POST', "/admin/filing/{$application->id}/reject", []],
        ['GET', "/admin/filing/{$application->id}/status", []],
        ['POST', "/applications/{$application->id}/status", ['new_status' => 'FILED', 'reason' => 'Matrix']],
    ];

    foreach ($endpoints as [$method, $uri, $body]) {
        $this->app['auth']->forgetGuards();
        $this->json($method, $uri, $body)->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->json($method, $uri, $body)->assertForbidden();
    }
});

it('lets director and MD drive the whole application surface', function (string $state) {
    $staff = User::factory()->{$state}()->create();

    $applicationId = $this->actingAs($staff)->postJson('/applications', [
        'title' => 'Staff Driven',
        'inventor_name' => 'Ada',
        'inventor_email' => 'ada@pdn.ac.lk',
    ])->assertOk()->json('id');

    $this->actingAs($staff)->getJson('/applications')->assertOk();
    $this->actingAs($staff)->patchJson("/applications/{$applicationId}", ['title' => 'Renamed'])->assertOk();
    $this->actingAs($staff)->postJson("/applications/{$applicationId}/deadlines?type=FILING&due_date=2027-01-01T00:00:00Z")->assertOk();
    $this->actingAs($staff)->getJson('/deadlines')->assertOk();
    $this->actingAs($staff)->postJson("/applications/{$applicationId}/office-actions?kind=ALLOWANCE&body=ok")->assertOk();
    $this->actingAs($staff)->postJson("/admin/filing/{$applicationId}/file")->assertOk();
    $this->actingAs($staff)->postJson("/applications/{$applicationId}/status", ['new_status' => 'ACKNOWLEDGED', 'reason' => 'Acked'])->assertNoContent();
    $this->actingAs($staff)->postJson("/admin/filing/{$applicationId}/defect-sheets?sheet_number=1&description=x")->assertOk();
    $this->actingAs($staff)->getJson("/admin/filing/{$applicationId}/defect-sheets")->assertOk();
    $this->actingAs($staff)->postJson("/applications/{$applicationId}/status", ['new_status' => 'ACKNOWLEDGED', 'reason' => 'Acked'])->assertNoContent();
    $this->actingAs($staff)->postJson("/admin/filing/{$applicationId}/grant?patent_number=LK/PAT/2026/3")->assertOk();
    $this->actingAs($staff)->getJson("/admin/filing/{$applicationId}/status")->assertOk();
})->with(['director', 'md']);
