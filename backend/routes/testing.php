<?php

use Illuminate\Support\Facades\Route;

/*
| Test-only routes that exercise each RBAC gate so the endpoint matrix is
| provable before the L3–L5 domain routes exist. Loaded only in local/testing.
*/

Route::middleware('auth')->group(function () {
    Route::post('/_test/any-auth', fn () => response()->json(null, 204));

    Route::middleware('role:director,md')->group(function () {
        Route::post('/_test/staff', fn () => response()->json(null, 204));
    });

    Route::post('/_test/md', fn () => response()->json(null, 204))->middleware('role:md');
});
