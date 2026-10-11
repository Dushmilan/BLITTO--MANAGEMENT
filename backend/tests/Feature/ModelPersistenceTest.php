<?php

use App\Models\Application;

it('persists and hydrates a domain model', function (string $modelClass, Closure $attributes, string $table) {
    $application = Application::query()->create(['title' => 'Persistence round-trip']);

    $model = $modelClass::query()->create($attributes($application));

    $reloaded = $modelClass::query()->findOrFail($model->getKey());

    expect($reloaded->getTable())->toBe($table)
        ->and($reloaded->getKey())->toBe($model->getKey());
})->with('domain models');
