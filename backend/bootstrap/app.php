<?php

use App\Domain\Authorization\DuplicateEmail;
use App\Domain\Authorization\InvalidInstitutionEmail;
use App\Domain\Lifecycle\IllegalTransition;
use App\Http\Middleware\EnsureRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Lifecycle violations are client errors, not server errors.
        $exceptions->render(
            fn (IllegalTransition $e, Request $request) => response()->json([
                'message' => $e->getMessage(),
            ], 422),
        );

        $exceptions->render(
            fn (AuthenticationException $e, Request $request) => response()->json([
                'message' => 'Unauthenticated.',
            ], 401),
        );

        $exceptions->render(
            fn (InvalidInstitutionEmail $e, Request $request) => response()->json([
                'message' => $e->getMessage(),
            ], 422),
        );

        $exceptions->render(
            fn (DuplicateEmail $e, Request $request) => response()->json([
                'message' => $e->getMessage(),
            ], 409),
        );
    })->create();
