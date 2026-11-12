<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `permission:content.review` — RBAC check on top of `admin.access`; 403 JSON otherwise. */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if (! $user || ! $user->hasPermission('admin.access')
            || collect($permissions)->contains(fn ($p) => ! $user->hasPermission($p))) {
            return response()->json(['message' => 'You do not have permission to do this.', 'code' => 'forbidden'], 403);
        }

        return $next($request);
    }
}
