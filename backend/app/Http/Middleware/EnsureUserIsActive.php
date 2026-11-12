<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->isActive()) {
            return response()->json(['message' => 'This account is suspended.', 'code' => 'account_suspended'], 403);
        }
        if ($user && (! $user->last_active_at || $user->last_active_at->lt(now()->subMinutes(15)))) {
            $user->forceFill(['last_active_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
