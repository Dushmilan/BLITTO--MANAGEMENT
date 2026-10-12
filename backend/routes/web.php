<?php

use App\Http\Controllers\ApplicationStatusController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
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

Route::post('/auth/login', [LoginController::class, 'store'])->name('auth.login');

Route::get('/auth/me', MeController::class)->middleware('auth')->name('auth.me');

Route::post('/auth/logout', [LogoutController::class, 'store'])->middleware('auth')->name('auth.logout');
