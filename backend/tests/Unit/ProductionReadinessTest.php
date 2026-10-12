<?php

use App\Support\ProductionReadiness;

it('allows a blank key in local', function () {
    (new ProductionReadiness('local', ''))->assert();
})->throwsNoExceptions();

it('allows a real key outside local', function () {
    (new ProductionReadiness('production', 'base64:'.str_repeat('a', 40)))->assert();
})->throwsNoExceptions();

it('refuses a blank key outside local', function () {
    (new ProductionReadiness('production', ''))->assert();
})->throws(RuntimeException::class);
