<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class LogoutController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->audit->record('auth:logout', actor: $actor, ipAddress: $request->ip());

        return response()->json(null, 204);
    }
}
