<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Authorization\AttemptThrottle;
use App\Domain\Authorization\EmailPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class LoginController extends Controller
{
    public function __construct(
        private readonly AttemptThrottle $throttle,
        private readonly AuditRecorder $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $email = EmailPolicy::normalize((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        if (! EmailPolicy::isInstitution($email)) {
            return response()->json(['message' => 'Institution email required'], 403);
        }

        if ($this->throttle->locked(AttemptThrottle::LOGIN, $email)) {
            return response()->json(['message' => 'Too many login attempts'], 429)
                ->header('Retry-After', '600');
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            $this->throttle->recordFailure(AttemptThrottle::LOGIN, $email);

            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $this->throttle->clear(AttemptThrottle::LOGIN, $email);
        $request->session()->regenerate();

        $this->audit->record('auth:login', actor: Auth::user(), ipAddress: $request->ip());

        return response()->json(Auth::user(), 200);
    }
}
