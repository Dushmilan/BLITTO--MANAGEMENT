<?php

namespace App\Http\Controllers;

use App\Support\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A form post rather than a link: the toggle changes state and must be
 * CSRF-protected (spec §7.3).
 */
final class ThemeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme' => ['required', Rule::in([Theme::LIGHT, Theme::DARK])],
        ]);

        return back()->withCookie(cookie(
            name: Theme::COOKIE,
            value: $validated['theme'],
            minutes: 60 * 24 * 365,
            sameSite: 'lax',
            httpOnly: false, // the pre-paint script must see an explicit choice
        ));
    }
}
