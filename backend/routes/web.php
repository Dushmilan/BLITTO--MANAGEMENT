<?php

use App\Http\Controllers\ApplicationStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => config('app.name'),
    ]);
})->name('health');

Route::post('/applications/{application}/status', [ApplicationStatusController::class, 'store'])
    ->name('applications.status');
