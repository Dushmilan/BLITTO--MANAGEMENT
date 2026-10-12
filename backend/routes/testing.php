<?php

use App\Domain\Authorization\AttemptThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Test-only routes that exercise each RBAC gate and the vault-unlock throttle
| so the endpoint matrix is provable before the L3–L5 domain routes exist.
| Loaded only in local/testing.
*/

Route::middleware('auth')->group(function () {
    Route::post('/_test/any-auth', fn () => response()->json(null, 204));

    Route::middleware('role:director,md')->group(function () {
        Route::post('/_test/staff', fn () => response()->json(null, 204));

        Route::post('/_test/vault-unlock', function (Request $request, AttemptThrottle $throttle) {
            $ip = (string) $request->ip();

            if ($throttle->locked(AttemptThrottle::VAULT_UNLOCK, $ip)) {
                return response()->json(['message' => 'Too many unlock attempts'], 429)
                    ->header('Retry-After', '600');
            }

            $throttle->recordFailure(AttemptThrottle::VAULT_UNLOCK, $ip);

            return response()->json(['message' => 'Invalid master key'], 401);
        });
    });

    Route::post('/_test/md', fn () => response()->json(null, 204))->middleware('role:md');
});
