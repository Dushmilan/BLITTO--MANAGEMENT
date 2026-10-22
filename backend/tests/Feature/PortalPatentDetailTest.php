<?php

use App\Domain\Vault\GrantedPatentService;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('renders the viewer’s own patent with its history', function () {
    $inventor = User::factory()->create(['email' => 'mine@pdn.ac.lk']);
    $application = Application::query()->create([
        'title' => 'Mine Patent',
        'inventor_email' => 'mine@pdn.ac.lk',
        'status' => 'FILED',
    ]);

    $this->actingAs($inventor)
        ->get("/user/patents/{$application->id}")
        ->assertOk()
        ->assertSee('Mine Patent')
        ->assertSee('Progress');
});

it('answers 404 for another inventor’s patent', function () {
    $stranger = User::factory()->create(['email' => 'stranger@pdn.ac.lk']);
    $application = Application::query()->create(['title' => 'Theirs', 'inventor_email' => 'theirs@pdn.ac.lk']);

    $this->actingAs($stranger)->get("/user/patents/{$application->id}")->assertNotFound();
});

it('offers a download link once a granted patent has a document', function () {
    Storage::fake('vault');
    $inventor = User::factory()->create(['email' => 'mine@pdn.ac.lk']);
    $application = Application::query()->create([
        'title' => 'Granted Patent',
        'inventor_email' => 'mine@pdn.ac.lk',
        'status' => 'GRANTED',
    ]);

    $this->actingAs(User::factory()->director()->create())
        ->postJson("/applications/{$application->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('granted.pdf', 'bytes'),
        ]);

    $this->app['auth']->forgetGuards();

    $this->actingAs($inventor)
        ->get("/user/patents/{$application->id}")
        ->assertOk()
        ->assertSee('Download patent document')
        ->assertDontSee('Request document');
});

it('offers the request form instead when the document is pending', function () {
    $inventor = User::factory()->create(['email' => 'mine@pdn.ac.lk']);
    $application = Application::query()->create([
        'title' => 'Pending Patent',
        'inventor_email' => 'mine@pdn.ac.lk',
        'status' => 'GRANTED',
    ]);

    $this->actingAs($inventor)
        ->get("/user/patents/{$application->id}")
        ->assertOk()
        ->assertSee('waiting for BLITTO to upload')
        ->assertSee('Request document')
        ->assertDontSee('Download patent document');
});

it('requests the document and flashes, then hides the form', function () {
    $inventor = User::factory()->create(['email' => 'mine@pdn.ac.lk']);
    $application = Application::query()->create([
        'title' => 'Pending Patent',
        'inventor_email' => 'mine@pdn.ac.lk',
        'status' => 'GRANTED',
    ]);

    $this->actingAs($inventor)
        ->from("/user/patents/{$application->id}")
        ->post("/applications/{$application->id}/request-document")
        ->assertRedirect("/user/patents/{$application->id}")
        ->assertSessionHas('status', 'Request sent. We will notify you when the document is ready.');

    $this->app['auth']->forgetGuards();

    $this->actingAs($inventor)
        ->get("/user/patents/{$application->id}")
        ->assertOk()
        ->assertSee('already requested')
        ->assertDontSee('name="request-document"', false);
});

it('reports hasOpenRequest for the audit-based open request', function () {
    $inventor = User::factory()->create(['email' => 'mine@pdn.ac.lk']);
    $application = Application::query()->create([
        'title' => 'Pending Patent',
        'inventor_email' => 'mine@pdn.ac.lk',
        'status' => 'GRANTED',
    ]);

    $granted = app(GrantedPatentService::class);

    expect($granted->hasOpenRequest($application, $inventor))->toBeFalse();

    $granted->requestDocument($application, $inventor);

    expect($granted->hasOpenRequest($application, $inventor))->toBeTrue();
});

it('keeps the JSON request contract', function () {
    $inventor = User::factory()->create(['email' => 'mine@pdn.ac.lk']);
    $application = Application::query()->create([
        'title' => 'Pending Patent',
        'inventor_email' => 'mine@pdn.ac.lk',
        'status' => 'GRANTED',
    ]);

    $this->actingAs($inventor)
        ->postJson("/applications/{$application->id}/request-document")
        ->assertOk()
        ->assertJsonPath('status', 'requested');
});
