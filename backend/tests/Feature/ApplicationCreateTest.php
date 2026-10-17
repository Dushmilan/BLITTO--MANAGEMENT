<?php

use App\Models\User;

it('creates an application as staff', function (string $state) {
    $staff = User::factory()->{$state}()->create(['email' => "{$state}@pdn.ac.lk"]);

    $response = $this->actingAs($staff)->postJson('/applications', [
        'title' => 'Solar Desalination Membrane',
        'inventor_name' => 'Dr. Ada Perera',
        'inventor_email' => 'user-a@pdn.ac.lk',
        'summary' => 'A low-cost membrane for solar-powered desalination.',
        'technology_area' => 'Materials',
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'DRAFT')
        ->assertJsonPath('title', 'Solar Desalination Membrane')
        ->assertJsonPath('inventor_email', 'user-a@pdn.ac.lk')
        ->assertJsonPath('technology_area', 'Materials')
        ->assertJsonPath('application_number', null)
        ->assertJsonPath('nipo_reference', null);

    $this->assertDatabaseHas('applications', ['title' => 'Solar Desalination Membrane', 'status' => 'DRAFT']);
    $this->assertDatabaseHas('inventors', ['inventor_email' => 'user-a@pdn.ac.lk', 'inventor_name' => 'Dr. Ada Perera']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'create_application', 'user_id' => $staff->id]);
})->with(['director', 'md']);

it('normalises the inventor email before storing it', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson('/applications', [
        'title' => 'Normalised',
        'inventor_name' => 'Ada',
        'inventor_email' => '  ADA@PDN.AC.LK ',
    ])->assertOk()->assertJsonPath('inventor_email', 'ada@pdn.ac.lk');
});

it('rejects a foreign inventor domain', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson('/applications', [
        'title' => 'Foreign',
        'inventor_name' => 'Ada',
        'inventor_email' => 'ada@gmail.com',
    ])->assertStatus(422)->assertJsonValidationErrors('inventor_email');
});

it('rejects lookalike institution domains', function (string $email) {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson('/applications', [
        'title' => 'Lookalike',
        'inventor_name' => 'Ada',
        'inventor_email' => $email,
    ])->assertStatus(422)->assertJsonValidationErrors('inventor_email');
})->with(['ada@pdn.ac.lk.evil.com', 'ada@fakepdn.ac.lk']);

it('rejects multi-author payloads', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson('/applications', [
        'title' => 'Lists',
        'inventor_name' => 'A, B',
        'inventor_email' => 'a@pdn.ac.lk',
    ])->assertStatus(422)->assertJsonValidationErrors('inventor_name');

    $this->actingAs($director)->postJson('/applications', [
        'title' => 'Lists',
        'inventor_name' => 'A',
        'inventor_email' => 'a@pdn.ac.lk; b@pdn.ac.lk',
    ])->assertStatus(422)->assertJsonValidationErrors('inventor_email');
});

it('rejects a blank inventor name and a missing title', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson('/applications', [
        'title' => 'Blank',
        'inventor_name' => '   ',
        'inventor_email' => 'a@pdn.ac.lk',
    ])->assertStatus(422)->assertJsonValidationErrors('inventor_name');

    $this->actingAs($director)->postJson('/applications', [
        'inventor_name' => 'A',
        'inventor_email' => 'a@pdn.ac.lk',
    ])->assertStatus(422)->assertJsonValidationErrors('title');
});

it('requires either the top-level inventor fields or an inventors list', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->postJson('/applications', ['title' => 'No inventor'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['inventor_name', 'inventor_email']);
});

it('creates from an inventors list when the top-level fields are omitted', function () {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director)->postJson('/applications', [
        'title' => 'Joint Patent',
        'inventors' => [
            ['inventor_name' => 'Alice', 'inventor_email' => 'alice@pdn.ac.lk'],
            ['inventor_name' => 'Bob', 'inventor_email' => 'bob@pdn.ac.lk'],
        ],
    ]);

    $response->assertOk()->assertJsonPath('inventor_name', 'Alice');
    $this->assertDatabaseHas('inventors', ['inventor_email' => 'bob@pdn.ac.lk']);
});

it('gates application creation to staff', function () {
    $payload = ['title' => 'Gated', 'inventor_name' => 'A', 'inventor_email' => 'a@pdn.ac.lk'];

    $this->postJson('/applications', $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson('/applications', $payload)->assertForbidden();
});
