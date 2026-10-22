<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ApplicationStatusController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LoginPageController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DocketingController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FilingController;
use App\Http\Controllers\GrantedPatentController;
use App\Http\Controllers\OfficeActionController;
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

Route::get('/login', LoginPageController::class)
    ->middleware('guest')
    ->name('login');

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

Route::post('/applications/{applicationId}/office-actions', [OfficeActionController::class, 'store'])
    ->middleware(['auth', 'role:director,md'])
    ->name('office-actions.store');

Route::post('/auth/login', [LoginController::class, 'store'])->name('auth.login');

Route::get('/auth/me', MeController::class)->middleware('auth')->name('auth.me');

Route::post('/auth/logout', [LogoutController::class, 'store'])->middleware('auth')->name('auth.logout');

Route::post('/auth/register', [RegisterController::class, 'store'])->middleware('auth')->name('auth.register');

Route::get('/users', [UserController::class, 'index'])
    ->middleware(['auth', 'role:md'])
    ->name('users.index');

Route::post('/applications/{applicationId}/deadlines', [DocketingController::class, 'store'])
    ->middleware(['auth', 'role:director,md'])
    ->name('deadlines.store');

Route::get('/deadlines', [DocketingController::class, 'index'])
    ->middleware(['auth', 'role:director,md'])
    ->name('deadlines.index');

Route::get('/analytics/portfolio', [AnalyticsController::class, 'portfolio'])
    ->middleware(['auth', 'role:director,md'])
    ->name('analytics.portfolio');

Route::get('/analytics/deadlines', [AnalyticsController::class, 'deadlines'])
    ->middleware(['auth', 'role:director,md'])
    ->name('analytics.deadlines');

Route::post('/applications/{applicationId}/documents', [DocumentController::class, 'store'])
    ->middleware(['auth', 'role:director,md'])
    ->name('documents.store');

Route::get('/applications/{applicationId}/documents', [DocumentController::class, 'index'])
    ->middleware(['auth', 'role:director,md'])
    ->name('documents.index');

Route::get('/applications/missing-documents', [DocumentController::class, 'missing'])
    ->middleware(['auth', 'role:director,md'])
    ->name('documents.missing');

Route::get('/applications/{applicationId}/documents/{documentId}/download', [DocumentController::class, 'download'])
    ->middleware(['auth', 'role:director,md'])
    ->name('documents.download');

Route::delete('/applications/{applicationId}/documents/{documentId}', [DocumentController::class, 'destroy'])
    ->middleware(['auth', 'role:director,md'])
    ->name('documents.destroy');

Route::get('/applications/{applicationId}/download', [GrantedPatentController::class, 'download'])
    ->middleware('auth')
    ->name('documents.granted-download');

Route::post('/applications/{applicationId}/request-document', [GrantedPatentController::class, 'requestDocument'])
    ->middleware('auth')
    ->name('documents.request');

Route::middleware(['auth', 'role:director,md'])
    ->prefix('admin/filing/{applicationId}')
    ->group(function () {
        Route::post('/file', [FilingController::class, 'file'])->name('filing.file');
        Route::post('/acknowledge', [FilingController::class, 'acknowledge'])->name('filing.acknowledge');
        Route::post('/defect-sheets', [FilingController::class, 'storeDefectSheet'])->name('filing.defect-sheets.store');
        Route::get('/defect-sheets', [FilingController::class, 'defectSheets'])->name('filing.defect-sheets.index');
        Route::post('/grant', [FilingController::class, 'grant'])->name('filing.grant');
        Route::post('/reject', [FilingController::class, 'reject'])->name('filing.reject');
        Route::get('/status', [FilingController::class, 'status'])->name('filing.status');
    });

if (app()->environment(['local', 'testing'])) {
    require __DIR__.'/testing.php';
}
