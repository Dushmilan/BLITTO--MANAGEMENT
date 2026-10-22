<?php

use App\Models\Application;
use App\Models\User;

function makeApplication(string $title, string $status, string $inventor = 'a@pdn.ac.lk', string $createdAt = '2026-01-01 00:00:00'): Application
{
    $application = new Application([
        'title' => $title,
        'status' => $status,
        'inventor_name' => 'Inventor',
        'inventor_email' => $inventor,
        'technology_area' => 'Pharma',
    ]);
    $application->created_at = $createdAt;
    $application->save();

    return $application;
}

it('lists the portfolio newest first with the reference fallback', function () {
    $older = makeApplication('Older Patent', 'FILED', 'a@pdn.ac.lk', '2026-01-01 00:00:00');
    makeApplication('Newer Patent', 'GRANTED', 'b@pdn.ac.lk', '2026-02-01 00:00:00');

    $this->actingAs(User::factory()->director()->create())
        ->get('/admin/patents')
        ->assertOk()
        ->assertSeeInOrder(['Newer Patent', 'Older Patent'])
        ->assertSee('Filed')
        ->assertSee(substr($older->id, 0, 8));
});

it('filters by status and by free text', function () {
    makeApplication('Solar Membrane', 'GRANTED');
    makeApplication('Crop Sensor', 'FILED');

    $director = User::factory()->director()->create();

    $this->actingAs($director)->get('/admin/patents?status=GRANTED')
        ->assertOk()->assertSee('Solar Membrane')->assertDontSee('Crop Sensor');

    $this->app['auth']->forgetGuards();

    $this->actingAs($director)->get('/admin/patents?q=crop')
        ->assertOk()->assertSee('Crop Sensor')->assertDontSee('Solar Membrane');
});

it('paginates fifteen rows per page', function () {
    foreach (range(1, 16) as $index) {
        makeApplication(
            sprintf('Patent %02d', $index),
            'DRAFT',
            'a@pdn.ac.lk',
            sprintf('2026-01-%02d 00:00:00', $index),
        );
    }

    $director = User::factory()->director()->create();

    $this->actingAs($director)->get('/admin/patents')
        ->assertOk()
        ->assertSee('Patent 16')
        ->assertDontSee('Patent 01');

    $this->app['auth']->forgetGuards();

    $this->actingAs($director)->get('/admin/patents?page=2')
        ->assertOk()
        ->assertSee('Patent 01')
        ->assertDontSee('Patent 16');
});

it('separates the empty and no-match states', function () {
    $director = User::factory()->director()->create();

    $this->actingAs($director)->get('/admin/patents')
        ->assertOk()->assertSee('No patents yet.');

    $this->app['auth']->forgetGuards();
    makeApplication('Solar Membrane', 'GRANTED');

    $this->actingAs($director)->get('/admin/patents?q=nothing-matches')
        ->assertOk()->assertSee('No patents match');
});

it('shows the Patents nav item to staff after this task', function () {
    $this->actingAs(User::factory()->director()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSeeInOrder(['Overview', 'Patents']);
});
