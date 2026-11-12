<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Uses the user's interface language (or Accept-Language for guests) for translated API strings. */
class SetRequestLocale
{
    private const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        // The sanctum guard covers both SPA sessions and bearer tokens (this runs before auth:sanctum).
        $locale = $request->user('sanctum')?->profile?->interfaceLanguage?->code
            ?? $request->getPreferredLanguage(self::SUPPORTED)
            ?? 'en';

        app()->setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : 'en');

        return $next($request);
    }
}
