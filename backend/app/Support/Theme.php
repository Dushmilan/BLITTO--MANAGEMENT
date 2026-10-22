<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Light/dark preference. Per-browser, not per-user: the choice lives in a
 * cookie so the server can render the right first paint (spec §7.3).
 */
final class Theme
{
    public const COOKIE = 'blitto-theme';

    public const LIGHT = 'light';

    public const DARK = 'dark';

    public static function fromRequest(Request $request): string
    {
        return $request->cookie(self::COOKIE) === self::DARK ? self::DARK : self::LIGHT;
    }

    public static function isDark(Request $request): bool
    {
        return self::fromRequest($request) === self::DARK;
    }
}
