<?php

namespace App\Http\Middleware;

use App\Domain\Authorization\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC gate mirroring the endpoint RBAC matrix. Roles are the lowercase
 * `Role` enum values; guests get 401, absent roles get 403.
 */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $allowed = array_map(static fn (string $role): Role => Role::from($role), $roles);

        if (! in_array(Role::from($user->role), $allowed, true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
