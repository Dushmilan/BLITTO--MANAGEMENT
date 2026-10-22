<?php

use App\Models\Application;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('gates every L4 endpoint against guests and plain users', function () {
    Storage::fake('vault');

    $director = User::factory()->director()->create();
    $application = Application::query()->create([
        'title' => 'L4 Matrix',
        'inventor_email' => 'someone-else@pdn.ac.lk',
    ]);

    $this->actingAs($director)->postJson("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('patent.pdf', 'bytes'),
    ])->assertOk();

    $documentId = $application->documents()->sole()->id;
    $stranger = User::factory()->create(['email' => 'stranger@pdn.ac.lk']);

    // [method, uri]
    $endpoints = [
        ['POST', "/applications/{$application->id}/documents"],
        ['GET', "/applications/{$application->id}/documents"],
        ['GET', '/applications/missing-documents'],
        ['GET', "/applications/{$application->id}/documents/{$documentId}/download"],
        ['DELETE', "/applications/{$application->id}/documents/{$documentId}"],
        ['GET', "/applications/{$application->id}/download"],
        ['POST', "/applications/{$application->id}/request-document"],
    ];

    foreach ($endpoints as [$method, $uri]) {
        $this->app['auth']->forgetGuards();
        $this->json($method, $uri)->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->actingAs($stranger)->json($method, $uri)->assertForbidden();
    }
});

it('lets staff drive every L4 staff endpoint', function (string $state) {
    Storage::fake('vault');
    $staff = User::factory()->{$state}()->create();
    $application = Application::query()->create(['title' => 'L4 Staff']);

    $this->actingAs($staff)->postJson("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('patent.pdf', 'bytes'),
    ])->assertOk();

    $documentId = $application->documents()->sole()->id;

    $this->actingAs($staff)->getJson("/applications/{$application->id}/documents")->assertOk();
    $this->actingAs($staff)->getJson('/applications/missing-documents')->assertOk();
    $this->actingAs($staff)->get("/applications/{$application->id}/documents/{$documentId}/download")->assertOk();
    $this->actingAs($staff)->deleteJson("/applications/{$application->id}/documents/{$documentId}")->assertNoContent();
})->with(['director', 'md']);
