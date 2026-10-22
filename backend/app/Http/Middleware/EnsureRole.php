<?php

namespace App\Http\Middleware;

use App\Domain\Authorization\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC gate mirroring the endpoint RBAC matrix. Roles are the lowercase
 * `Role` enum values; guests get 401, absent roles get 403 — as JSON for API
 * clients and as a redirect / error page for browsers (L6 spec §5.2).
 */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Unauthenticated.'], 401)
                : redirect()->guest(route('login'));
        }

        $allowed = array_map(static fn (string $role): Role => Role::from($role), $roles);

        if (! in_array(Role::from($user->role), $allowed, true)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Forbidden.'], 403)
                : abort(403, 'Forbidden.');
        }

        return $next($request);
    }
}
