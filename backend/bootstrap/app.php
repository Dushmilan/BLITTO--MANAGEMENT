<?php

use App\Domain\ApplicationIntake\ApplicationNotFound;
use App\Domain\Authorization\DuplicateEmail;
use App\Domain\Authorization\InvalidInstitutionEmail;
use App\Domain\Filing\AcknowledgementWithoutFiling;
use App\Domain\Filing\DefectSheetRejected;
use App\Domain\Lifecycle\IllegalTransition;
use App\Domain\Vault\DocumentContentMissing;
use App\Domain\Vault\DocumentIsImmutable;
use App\Domain\Vault\DocumentNotFound;
use App\Domain\Vault\DocumentPending;
use App\Domain\Vault\DocumentTooLarge;
use App\Domain\Vault\InvalidDocumentFilename;
use App\Domain\Vault\NotYourPatent;
use App\Domain\Vault\PatentNotGranted;
use App\Domain\Vault\UnsupportedFileType;
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

        $exceptions->render(
            fn (ApplicationNotFound $e) => response()->json([
                'message' => $e->getMessage(),
            ], 404),
        );

        $exceptions->render(
            fn (DefectSheetRejected $e) => response()->json([
                'message' => $e->getMessage(),
            ], 422),
        );

        $exceptions->render(
            fn (AcknowledgementWithoutFiling $e) => response()->json([
                'message' => $e->getMessage(),
            ], 404),
        );

        $exceptions->render(
            fn (UnsupportedFileType $e) => response()->json([
                'message' => $e->getMessage(),
            ], 422),
        );

        $exceptions->render(
            fn (InvalidDocumentFilename $e) => response()->json([
                'message' => $e->getMessage(),
            ], 422),
        );

        $exceptions->render(
            fn (DocumentTooLarge $e) => response()->json([
                'message' => $e->getMessage(),
            ], 413),
        );

        $exceptions->render(
            fn (DocumentContentMissing $e) => response()->json([
                'message' => $e->getMessage(),
            ], 404),
        );

        $exceptions->render(
            fn (DocumentNotFound $e) => response()->json([
                'message' => $e->getMessage(),
            ], 404),
        );

        $exceptions->render(
            fn (DocumentIsImmutable $e) => response()->json([
                'message' => $e->getMessage(),
            ], 409),
        );

        $exceptions->render(
            fn (NotYourPatent $e) => response()->json([
                'message' => $e->getMessage(),
            ], 403),
        );

        $exceptions->render(
            fn (PatentNotGranted $e) => response()->json([
                'message' => $e->getMessage(),
            ], 403),
        );

        $exceptions->render(
            fn (DocumentPending $e) => response()->json([
                'message' => $e->getMessage(),
                'code' => 'document_pending',
            ], 409),
        );
    })->create();
