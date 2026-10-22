<?php

use App\Models\User;

it('renders light by default and dark when the cookie says so', function () {
    $md = User::factory()->md()->create();

    $this->actingAs($md)->get('/admin')->assertOk()->assertDontSee('class="dark"', false);

    $this->actingAs($md)
        ->withCookie('blitto-theme', 'dark')
        ->get('/admin')
        ->assertOk()
        ->assertSee('class="dark"', false);
});

it('sets the cookie and redirects back', function () {
    $md = User::factory()->md()->create();

    $this->actingAs($md)
        ->from('/admin')
        ->post('/theme', ['theme' => 'dark'])
        ->assertRedirect('/admin')
        ->assertCookie('blitto-theme', 'dark');
});

it('rejects an unknown theme value', function () {
    $this->actingAs(User::factory()->create())
        ->post('/theme', ['theme' => 'midnight'])
        ->assertSessionHasErrors('theme');
});

it('sends a theme cookie the page script can read', function () {
    $response = $this->actingAs(User::factory()->md()->create())
        ->from('/admin')
        ->post('/theme', ['theme' => 'dark']);

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'blitto-theme');

    expect($cookie)->not->toBeNull();
    expect($cookie->isHttpOnly())->toBeFalse();
});
