<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Authorization\EmailPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class LoginController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $email = EmailPolicy::normalize((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        if (! EmailPolicy::isInstitution($email)) {
            return response()->json(['message' => 'Institution email required'], 403);
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $request->session()->regenerate();

        return response()->json(Auth::user(), 200);
    }
}
