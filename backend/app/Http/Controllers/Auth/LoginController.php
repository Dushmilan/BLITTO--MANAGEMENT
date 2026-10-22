<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Authorization\AttemptThrottle;
use App\Domain\Authorization\EmailPolicy;
use App\Http\Controllers\Controller;
use App\Support\HomeFor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class LoginController extends Controller
{
    public function __construct(
        private readonly AttemptThrottle $throttle,
        private readonly AuditRecorder $audit,
    ) {}

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $email = EmailPolicy::normalize((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        if (! EmailPolicy::isInstitution($email)) {
            return $this->failure($request, 'Enter a valid institution email address.', 403, 'Institution email required');
        }

        if ($this->throttle->locked(AttemptThrottle::LOGIN, $email)) {
            $response = $this->failure($request, 'Too many attempts. Please wait a few minutes and try again.', 429, 'Too many login attempts');

            return $request->expectsJson() ? $response->header('Retry-After', '600') : $response;
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            $this->throttle->recordFailure(AttemptThrottle::LOGIN, $email);

            return $this->failure($request, 'Incorrect email or password. Please try again.', 401, 'Invalid credentials');
        }

        $this->throttle->clear(AttemptThrottle::LOGIN, $email);
        $request->session()->regenerate();

        $this->audit->record('auth:login', actor: Auth::user(), ipAddress: $request->ip());

        if ($request->expectsJson()) {
            return response()->json(Auth::user(), 200);
        }

        return redirect()->intended(HomeFor::path(Auth::user()));
    }

    /**
     * One failure, two representations: JSON keeps the status and body it has
     * always returned; an HTML form comes back with the field error.
     */
    private function failure(Request $request, string $message, int $status, string $jsonMessage): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $jsonMessage], $status);
        }

        return back()->withInput($request->only('email'))->withErrors(['email' => $message]);
    }
}
