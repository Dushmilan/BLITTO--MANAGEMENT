<?php

use App\Models\Application;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('uploads a document from the panel and flashes', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Draft One', 'status' => 'DRAFT']);

    $this->actingAs(User::factory()->director()->create())
        ->from("/admin/patents/{$application->id}")
        ->post("/applications/{$application->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('specification.pdf', 'bytes'),
        ])
        ->assertRedirect("/admin/patents/{$application->id}")
        ->assertSessionHas('status', 'Document uploaded.');

    expect($application->documents()->count())->toBe(1);
});

it('lists uploaded documents on the page', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Draft One']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('specification.pdf', 'bytes'),
    ]);

    $this->app['auth']->forgetGuards();

    $this->actingAs($director)
        ->get("/admin/patents/{$application->id}")
        ->assertOk()
        ->assertSee('specification.pdf')
        ->assertSee('Delete');
});

it('flashes the size-cap message instead of a server error', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Draft One']);
    $path = tempnam(sys_get_temp_dir(), 'blitto').'.pdf';
    file_put_contents($path, 'bytes');

    // PHP rejected the upload before Laravel saw it (upload_max_filesize).
    $oversized = new UploadedFile($path, 'huge.pdf', null, UPLOAD_ERR_INI_SIZE, true);

    $this->actingAs(User::factory()->director()->create())
        ->from("/admin/patents/{$application->id}")
        ->post("/applications/{$application->id}/documents", ['file' => $oversized])
        ->assertRedirect("/admin/patents/{$application->id}")
        ->assertSessionHasErrors('form');

    expect($application->documents()->count())->toBe(0);
});

it('deletes a document and flashes', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Draft One']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('specification.pdf', 'bytes'),
    ]);

    $documentId = $application->documents()->sole()->id;

    $this->actingAs($director)
        ->from("/admin/patents/{$application->id}")
        ->delete("/applications/{$application->id}/documents/{$documentId}")
        ->assertRedirect("/admin/patents/{$application->id}")
        ->assertSessionHas('status', 'Document deleted.');

    expect($application->documents()->count())->toBe(0);
});

it('refuses to delete a document after filing and hides the control', function () {
    Storage::fake('vault');
    $application = Application::query()->create(['title' => 'Draft One', 'status' => 'DRAFT']);
    $director = User::factory()->director()->create();

    $this->actingAs($director)->post("/applications/{$application->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('specification.pdf', 'bytes'),
    ]);
    $this->actingAs($director)->post("/admin/filing/{$application->id}/file");
    $documentId = $application->documents()->sole()->id;

    $this->actingAs($director)
        ->from("/admin/patents/{$application->id}")
        ->delete("/applications/{$application->id}/documents/{$documentId}")
        ->assertSessionHasErrors('form');

    $this->app['auth']->forgetGuards();

    $this->actingAs($director)
        ->get("/admin/patents/{$application->id}")
        ->assertOk()
        ->assertSee('Documents are locked once the application is filed')
        ->assertDontSee('Delete</');
});
