<?php

use App\Domain\ApplicationIntake\ApplicationNotFound;
use App\Domain\Authorization\DuplicateEmail;
use App\Domain\Authorization\InvalidInstitutionEmail;
use App\Domain\Filing\AcknowledgementWithoutFiling;
use App\Domain\Filing\DefectSheetRejected;
use App\Domain\Lifecycle\IllegalTransition;
use App\Domain\Vault\DocumentAlreadyRequested;
use App\Domain\Vault\DocumentContentMissing;
use App\Domain\Vault\DocumentIsImmutable;
use App\Domain\Vault\DocumentNotFound;
use App\Domain\Vault\DocumentPending;
use App\Domain\Vault\DocumentRequestRefused;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

        /*
         * One failure, two representations (spec §6). JSON keeps the exact
         * status and body it has always returned; an HTML request comes back
         * with a form error instead of a JSON blob.
         */
        $domainError = function (
            Throwable $exception,
            Request $request,
            int $status,
            array $extra = [],
        ): JsonResponse|RedirectResponse {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $exception->getMessage()] + $extra, $status);
            }

            // A navigation (GET) has no form to return to: render the status as
            // an error page. Only a submitted form gets the redirect-back.
            if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
                abort($status, $exception->getMessage());
            }

            return back()->withErrors(['form' => $exception->getMessage()])
                ->withInput($request->except('password'));
        };

        // Lifecycle violations are client errors, not server errors.
        $exceptions->render(
            fn (IllegalTransition $e, Request $request) => $domainError($e, $request, 422),
        );

        $exceptions->render(
            fn (InvalidInstitutionEmail $e, Request $request) => $domainError($e, $request, 422),
        );

        $exceptions->render(
            fn (DuplicateEmail $e, Request $request) => $domainError($e, $request, 409),
        );

        $exceptions->render(
            fn (ApplicationNotFound $e, Request $request) => $domainError($e, $request, 404),
        );

        $exceptions->render(
            fn (DefectSheetRejected $e, Request $request) => $domainError($e, $request, 422),
        );

        $exceptions->render(
            fn (AcknowledgementWithoutFiling $e, Request $request) => $domainError($e, $request, 404),
        );

        $exceptions->render(
            fn (UnsupportedFileType $e, Request $request) => $domainError($e, $request, 422),
        );

        $exceptions->render(
            fn (InvalidDocumentFilename $e, Request $request) => $domainError($e, $request, 422),
        );

        $exceptions->render(
            fn (DocumentTooLarge $e, Request $request) => $domainError($e, $request, 413),
        );

        $exceptions->render(
            fn (DocumentContentMissing $e, Request $request) => $domainError($e, $request, 404),
        );

        $exceptions->render(
            fn (DocumentNotFound $e, Request $request) => $domainError($e, $request, 404),
        );

        $exceptions->render(
            fn (DocumentIsImmutable $e, Request $request) => $domainError($e, $request, 409),
        );

        $exceptions->render(
            fn (NotYourPatent $e, Request $request) => $domainError($e, $request, 403),
        );

        $exceptions->render(
            fn (PatentNotGranted $e, Request $request) => $domainError($e, $request, 403),
        );

        $exceptions->render(
            fn (DocumentPending $e, Request $request) => $domainError($e, $request, 409, ['code' => 'document_pending']),
        );

        $exceptions->render(
            fn (DocumentAlreadyRequested $e, Request $request) => $domainError($e, $request, 429),
        );

        $exceptions->render(
            fn (DocumentRequestRefused $e, Request $request) => $domainError($e, $request, 409),
        );

        // JSON callers keep 401; browsers fall through to Laravel's
        // redirect-to-login (which also records the intended URL).
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json(['message' => 'Unauthenticated.'], 401);
        });
    })->create();
