<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Presentation only: the form posts to the existing LoginController.
 */
final class LoginPageController extends Controller
{
    public function __invoke(): View
    {
        return view('auth.login');
    }
}
