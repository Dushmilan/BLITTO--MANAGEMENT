<?php

use App\Models\Application;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('escapes a hostile patent title on the staff list', function () {
    Application::query()->create([
        'title' => '<script>alert("xss")</script>',
        'inventor_email' => 'a@pdn.ac.lk',
    ]);

    $response = $this->actingAs(User::factory()->director()->create())
        ->get('/admin/patents')
        ->assertOk();

    $response->assertDontSee('<script>alert("xss")</script>', false);
    $response->assertSee('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false);
});

it('escapes a hostile filename in the documents panel', function () {
    $application = Application::query()->create(['title' => 'Safe Title']);

    Storage::fake('vault');

    $this->actingAs(User::factory()->director()->create())
        ->postJson("/applications/{$application->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('<img src=x onerror=alert(1)>.pdf', 'bytes'),
        ]);

    $this->app['auth']->forgetGuards();

    $this->actingAs(User::factory()->director()->create())
        ->get("/admin/patents/{$application->id}")
        ->assertOk()
        ->assertDontSee('<img src=x onerror=alert(1)>', false);
});
