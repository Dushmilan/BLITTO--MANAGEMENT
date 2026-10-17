<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ApplicationStatusController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\UserController;
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

Route::post('/applications', [ApplicationController::class, 'store'])
    ->middleware(['auth', 'role:director,md'])
    ->name('applications.store');

Route::get('/applications', [ApplicationController::class, 'index'])
    ->middleware('auth')
    ->name('applications.index');

Route::patch('/applications/{applicationId}', [ApplicationController::class, 'update'])
    ->middleware(['auth', 'role:director,md'])
    ->name('applications.update');

Route::get('/applications/{applicationId}/history', [ApplicationController::class, 'history'])
    ->middleware('auth')
    ->name('applications.history');

Route::post('/applications/{application}/status', [ApplicationStatusController::class, 'store'])
    ->middleware(['auth', 'role:director,md'])
    ->name('applications.status');

Route::post('/auth/login', [LoginController::class, 'store'])->name('auth.login');

Route::get('/auth/me', MeController::class)->middleware('auth')->name('auth.me');

Route::post('/auth/logout', [LogoutController::class, 'store'])->middleware('auth')->name('auth.logout');

Route::post('/auth/register', [RegisterController::class, 'store'])->middleware('auth')->name('auth.register');

Route::get('/users', [UserController::class, 'index'])
    ->middleware(['auth', 'role:md'])
    ->name('users.index');

if (app()->environment(['local', 'testing'])) {
    require __DIR__.'/testing.php';
}
