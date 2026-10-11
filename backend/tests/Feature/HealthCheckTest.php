<?php

use function Pest\Laravel\get;

it('reports service health', function () {
    get('/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'service' => config('app.name'),
        ]);
});
