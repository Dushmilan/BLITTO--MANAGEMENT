<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Authorization\Role;
use App\Domain\Authorization\UserDirectory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class RegisterController extends Controller
{
    public function __construct(private readonly UserDirectory $directory) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate(['role' => ['sometimes', Rule::enum(Role::class)]]);

        $role = Role::from((string) $request->input('role', Role::USER->value));
        $actor = $request->user();

        // Only the MD may create director/MD roles; everyone may self-register as user.
        if ($role !== Role::USER && Role::from($actor->role) !== Role::MD) {
            return response()->json(['message' => 'Only MD may create director/MD roles'], 403);
        }

        // bcrypt truncates silently past 72 bytes — refuse rather than mis-hash.
        if ($request->filled('password')
            && strlen((string) $request->input('password')) > UserDirectory::MAX_PASSWORD_BYTES) {
            return response()->json(['message' => 'password exceeds 72 bytes (bcrypt limit)'], 422);
        }

        $user = $this->directory->register(
            email: (string) $request->input('email', ''),
            role: $role,
            password: $request->filled('password') ? (string) $request->input('password') : null,
            userCode: $request->filled('user_code') ? (string) $request->input('user_code') : null,
            actor: $actor,
            ipAddress: $request->ip(),
        );

        return response()->json($user, 200);
    }
}
