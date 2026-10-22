<?php

use App\Models\Application;
use App\Models\FilingRecord;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('offers File on a draft and nothing else', function () {
    $application = Application::query()->create(['title' => 'Draft One', 'status' => 'DRAFT']);

    $response = $this->actingAs(User::factory()->director()->create())
        ->get("/admin/patents/{$application->id}")
        ->assertOk();

    $response->assertSee('File patent');
    $response->assertDontSee('Acknowledge NIPO');
    $response->assertDontSee('Grant patent');
});

it('files a patent from the panel and flashes', function () {
    $application = Application::query()->create(['title' => 'Draft One', 'status' => 'DRAFT']);

    $this->actingAs(User::factory()->director()->create())
        ->from("/admin/patents/{$application->id}")
        ->post("/admin/filing/{$application->id}/file")
        ->assertRedirect("/admin/patents/{$application->id}")
        ->assertSessionHas('status', 'Filing recorded.');

    expect($application->fresh()->status)->toBe('FILED');
});

it('offers Acknowledge once a filing record exists', function () {
    $application = Application::query()->create(['title' => 'Draft One', 'status' => 'DRAFT']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)->post("/admin/filing/{$application->id}/file");
    $this->app['auth']->forgetGuards();

    $this->actingAs($director)
        ->get("/admin/patents/{$application->id}")
        ->assertOk()
        ->assertSee('Acknowledge NIPO')
        ->assertDontSee('Grant patent');
});

it('records a defect sheet and lists it', function () {
    $application = Application::query()->create(['title' => 'Ack One', 'status' => 'ACKNOWLEDGED']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)
        ->from("/admin/patents/{$application->id}")
        ->post("/admin/filing/{$application->id}/defect-sheets", ['sheet_number' => 1, 'description' => 'Claims too broad'])
        ->assertRedirect("/admin/patents/{$application->id}")
        ->assertSessionHas('status', 'Defect sheet recorded.');

    $this->app['auth']->forgetGuards();

    $this->actingAs($director)
        ->get("/admin/patents/{$application->id}")
        ->assertOk()
        ->assertSee('Claims too broad');

    expect($application->fresh()->status)->toBe('DEFECT_SHEET_1');
});

it('grants a patent and warns when no document exists', function () {
    $application = Application::query()->create(['title' => 'Ack One', 'status' => 'ACKNOWLEDGED']);

    $this->actingAs(User::factory()->director()->create())
        ->from("/admin/patents/{$application->id}")
        ->post("/admin/filing/{$application->id}/grant", ['patent_number' => 'LK-2026-0042'])
        ->assertRedirect("/admin/patents/{$application->id}")
        ->assertSessionHas('status', 'Patent granted as LK-2026-0042.')
        ->assertSessionHas('warning');

    expect($application->fresh()->status)->toBe('GRANTED');
});

it('turns a filing step out of state into a form error with no state change', function () {
    // No filing record: acknowledging NIPO is out of state by definition.
    $application = Application::query()->create(['title' => 'Never Filed', 'status' => 'DRAFT']);

    $this->actingAs(User::factory()->director()->create())
        ->from("/admin/patents/{$application->id}")
        ->post("/admin/filing/{$application->id}/acknowledge")
        ->assertRedirect("/admin/patents/{$application->id}")
        ->assertSessionHasErrors('form');

    expect($application->fresh()->status)->toBe('DRAFT');
});

it('warns before granting when no document is uploaded', function () {
    $application = Application::query()->create(['title' => 'No Doc', 'status' => 'DRAFT']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)->post("/admin/filing/{$application->id}/file");
    $this->actingAs($director)->post("/admin/filing/{$application->id}/acknowledge");

    $this->app['auth']->forgetGuards();

    $this->actingAs($director)
        ->get("/admin/patents/{$application->id}")
        ->assertOk()
        ->assertSee('No document is uploaded yet');
});

it('does not warn before granting when a document exists', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'With Doc', 'status' => 'DRAFT']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)->post("/admin/filing/{$application->id}/file");
    $this->actingAs($director)->post("/admin/filing/{$application->id}/acknowledge");
    $this->actingAs($director)->postJson("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('granted.pdf', 'bytes'),
    ]);

    $this->app['auth']->forgetGuards();

    $this->actingAs($director)
        ->get("/admin/patents/{$application->id}")
        ->assertOk()
        ->assertDontSee('No document is uploaded yet');
});

it('confirms before granting', function () {
    $application = Application::query()->create(['title' => 'Ack One', 'status' => 'DRAFT']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)->post("/admin/filing/{$application->id}/file");
    $this->actingAs($director)->post("/admin/filing/{$application->id}/acknowledge");

    $this->app['auth']->forgetGuards();

    $this->actingAs($director)
        ->get("/admin/patents/{$application->id}")
        ->assertOk()
        ->assertSee('data-dialog-open="#confirm-grant"', false)
        ->assertSee('Granting is permanent');
});

it('backfills the filing record when a FILED application is filed from the panel', function () {
    $application = Application::query()->create(['title' => 'Statused', 'status' => 'DRAFT']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)->post("/applications/{$application->id}/status", [
        'new_status' => 'FILED',
        'reason' => 'Set through the generic status form',
    ]);

    expect(FilingRecord::query()->where('application_id', $application->id)->exists())->toBeFalse();

    $this->actingAs($director)
        ->from("/admin/patents/{$application->id}")
        ->post("/admin/filing/{$application->id}/file")
        ->assertRedirect("/admin/patents/{$application->id}")
        ->assertSessionHas('status', 'Filing recorded.');

    expect(FilingRecord::query()->where('application_id', $application->id)->exists())->toBeTrue();
});
