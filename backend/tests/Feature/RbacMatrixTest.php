<?php

use App\Models\User;

it('enforces the endpoint RBAC matrix across every role', function () {
    $users = [
        'user' => User::factory()->create(['email' => 'user-a@pdn.ac.lk']),
        'director' => User::factory()->director()->create(['email' => 'director@pdn.ac.lk']),
        'md' => User::factory()->md()->create(['email' => 'md@pdn.ac.lk']),
    ];

    // [method, uri, expected status per actor]
    $matrix = [
        ['POST', '/_test/any-auth', ['guest' => 401, 'user' => 204, 'director' => 204, 'md' => 204]],
        ['POST', '/_test/staff', ['guest' => 401, 'user' => 403, 'director' => 204, 'md' => 204]],
        ['POST', '/_test/md', ['guest' => 401, 'user' => 403, 'director' => 403, 'md' => 204]],
        ['GET', '/auth/me', ['guest' => 401, 'user' => 200, 'director' => 200, 'md' => 200]],
        ['GET', '/users', ['guest' => 401, 'user' => 403, 'director' => 403, 'md' => 200]],
    ];

    foreach ($matrix as [$method, $uri, $expectations]) {
        foreach ($expectations as $actor => $expected) {
            // actingAs() persists for the rest of the test, so clear the
            // resolved guards to give every cell a clean authentication state.
            $this->app['auth']->forgetGuards();

            $response = $actor === 'guest'
                ? $this->json($method, $uri)
                : $this->actingAs($users[$actor])->json($method, $uri);

            $response->assertStatus($expected, "{$actor} {$method} {$uri}");
        }
    }
});
