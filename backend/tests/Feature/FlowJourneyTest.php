<?php

use App\Models\Application;
use App\Models\Inventor;
use App\Models\Notification;
use App\Models\User;

it('runs the J3 rejection journey and shows the state to the inventor', function () {
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    $inventor = User::factory()->create(['email' => 'j-inv@pdn.ac.lk']);

    $applicationId = $this->actingAs($director)->postJson('/applications', [
        'title' => 'Journey Widget',
        'inventor_name' => 'Journey Inv',
        'inventor_email' => 'j-inv@pdn.ac.lk',
        'summary' => 's',
    ])->assertOk()->json('id');

    $this->actingAs($director)->postJson("/applications/{$applicationId}/status", [
        'new_status' => 'FILED',
        'reason' => 'Filed with NIPO',
    ])->assertNoContent();

    $this->actingAs($director)->postJson("/applications/{$applicationId}/status", [
        'new_status' => 'REJECTED',
        'reason' => 'Prior art',
    ])->assertNoContent();

    $listed = $this->actingAs($inventor)->getJson('/applications')->assertOk()->json();

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['id'])->toBe($applicationId)
        ->and($listed[0]['status'])->toBe('REJECTED');
});

it('runs the J6 second-user isolation journey', function () {
    $director = User::factory()->director()->create();
    $owner = User::factory()->create(['email' => 'owner@pdn.ac.lk']);
    $other = User::factory()->create(['email' => 'other@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Private', 'inventor_email' => 'owner@pdn.ac.lk']);

    $this->actingAs($director)->postJson("/applications/{$application->id}/status", [
        'new_status' => 'FILED',
        'reason' => 'Filed with NIPO',
    ])->assertNoContent();

    expect($this->actingAs($owner)->getJson('/applications')->json())->toHaveCount(1)
        ->and($this->actingAs($other)->getJson('/applications')->json())->toBe([])
        ->and($this->actingAs($other)->getJson("/applications/{$application->id}/history")->getStatusCode())->toBe(403)
        ->and($this->actingAs($owner)->getJson("/applications/{$application->id}/history")->getStatusCode())->toBe(200);
});

it('runs the multi-user staff interplay journey', function () {
    $director = User::factory()->director()->create(['email' => 'director@pdn.ac.lk']);
    $md = User::factory()->md()->create(['email' => 'md@pdn.ac.lk']);

    $granted = Application::query()->create(['title' => 'Grant Me', 'inventor_email' => 'granted@pdn.ac.lk']);
    $rejected = Application::query()->create(['title' => 'Reject Me', 'inventor_email' => 'rejected@pdn.ac.lk']);
    $objected = Application::query()->create(['title' => 'Object Me', 'inventor_email' => 'objected@pdn.ac.lk']);

    foreach ([$granted, $rejected, $objected] as $application) {
        Inventor::query()->create([
            'application_id' => $application->id,
            'inventor_name' => 'Inventor',
            'inventor_email' => $application->inventor_email,
        ]);
    }

    // Director prosecutes one application through office action to grant.
    $this->actingAs($director)
        ->postJson("/applications/{$granted->id}/office-actions?kind=OBJECTION&body=clarity")
        ->assertOk();
    $this->actingAs($director)->postJson("/applications/{$granted->id}/status", ['new_status' => 'FILED', 'reason' => 'Filed'])
        ->assertNoContent();
    $this->actingAs($director)->postJson("/applications/{$granted->id}/status", ['new_status' => 'ACKNOWLEDGED', 'reason' => 'Acked'])
        ->assertNoContent();
    $this->actingAs($md)->postJson("/admin/filing/{$granted->id}/grant?patent_number=LK/PAT/2026/1")
        ->assertOk()->assertJsonPath('status', 'GRANTED');

    // The MD rejects another, and the office action on the third left a deadline.
    $this->actingAs($md)->postJson("/applications/{$rejected->id}/status", ['new_status' => 'FILED', 'reason' => 'Filed'])
        ->assertNoContent();
    $this->actingAs($md)->postJson("/admin/filing/{$rejected->id}/reject")->assertOk();

    expect($this->actingAs($director)->getJson('/deadlines')->assertOk()->json())->toHaveCount(1)
        ->and($granted->refresh()->status)->toBe('GRANTED')
        ->and($rejected->refresh()->status)->toBe('REJECTED')
        ->and($objected->refresh()->status)->toBe('DRAFT');
});

it('notifies a co-inventor on every filing step', function () {
    $director = User::factory()->director()->create();
    $application = Application::query()->create(['title' => 'Joint', 'inventor_email' => 'ada@pdn.ac.lk']);

    foreach (['ada@pdn.ac.lk', 'bob@pdn.ac.lk'] as $email) {
        Inventor::query()->create([
            'application_id' => $application->id,
            'inventor_name' => 'Inventor',
            'inventor_email' => $email,
        ]);
    }

    $this->actingAs($director)->postJson("/admin/filing/{$application->id}/file")->assertOk();

    $this->assertDatabaseHas('notifications', ['recipient_email' => 'bob@pdn.ac.lk', 'subject' => 'Patent Filed']);
    expect(Notification::query()->count())->toBe(2);
});
