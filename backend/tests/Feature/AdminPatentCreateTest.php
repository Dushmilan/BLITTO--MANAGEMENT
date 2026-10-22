<?php

use App\Models\Application;
use App\Models\Inventor;
use App\Models\User;

it('creates a patent from the form and returns to the list', function () {
    $this->actingAs(User::factory()->director()->create())
        ->from('/admin/patents/create')
        ->post('/applications', [
            'title' => 'Solar Desalination Membrane',
            'technology_area' => 'Materials',
            'inventors' => [
                ['inventor_name' => 'Ada Lovelace', 'inventor_email' => 'ada@pdn.ac.lk'],
            ],
        ])
        ->assertRedirectContains('/admin/patents/')
        ->assertSessionHas('status', 'Patent application created.');

    expect(Application::query()->where('title', 'Solar Desalination Membrane')->exists())->toBeTrue();
});

it('returns validation errors to the form', function () {
    $this->actingAs(User::factory()->director()->create())
        ->from('/admin/patents/create')
        ->post('/applications', ['title' => 'No Inventors'])
        ->assertRedirect('/admin/patents/create')
        ->assertSessionHasErrors('inventor_name');
});

it('rejects an institution-external inventor email', function () {
    $this->actingAs(User::factory()->director()->create())
        ->from('/admin/patents/create')
        ->post('/applications', [
            'title' => 'Outside Domain',
            'inventors' => [['inventor_name' => 'Someone', 'inventor_email' => 'someone@gmail.com']],
        ])
        ->assertSessionHasErrors('inventors.0.inventor_email');
});

it('keeps the JSON create contract', function () {
    $this->actingAs(User::factory()->director()->create())
        ->postJson('/applications', [
            'title' => 'Via API',
            'inventor_name' => 'Api Inventor',
            'inventor_email' => 'api@pdn.ac.lk',
        ])
        ->assertOk()
        ->assertJsonPath('title', 'Via API');
});

it('renders the create page with two inventor rows', function () {
    $this->actingAs(User::factory()->director()->create())
        ->get('/admin/patents/create')
        ->assertOk()
        ->assertSee('name="inventors[0][inventor_name]"', false)
        ->assertSee('name="inventors[1][inventor_name]"', false);
});

it('applies the institution gate to the inventor array on the API too', function () {
    $this->actingAs(User::factory()->director()->create())
        ->postJson('/applications', [
            'title' => 'Outside Domain Via API',
            'inventors' => [['inventor_name' => 'Someone', 'inventor_email' => 'someone@gmail.com']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('inventors.0.inventor_email');
});

it('creates a patent when the second inventor row is left blank', function () {
    $this->actingAs(User::factory()->director()->create())
        ->from('/admin/patents/create')
        ->post('/applications', [
            'title' => 'Single Inventor',
            'inventors' => [
                ['inventor_name' => 'Ada Lovelace', 'inventor_email' => 'ada@pdn.ac.lk'],
                ['inventor_name' => '', 'inventor_email' => ''],
            ],
        ])
        ->assertRedirectContains('/admin/patents/')
        ->assertSessionHas('status', 'Patent application created.');

    $application = Application::query()->where('title', 'Single Inventor')->sole();

    expect(Inventor::query()->where('application_id', $application->id)->count())->toBe(1);
});
