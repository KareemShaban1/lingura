<?php

use App\Domain\AI\Exceptions\AiException;
use App\Domain\AI\Exceptions\CapabilityUnavailable;
use App\Domain\Shared\DomainError;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetRequestLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->api(append: [SetRequestLocale::class]);
        $middleware->alias(['active' => EnsureUserIsActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (DomainError $e) => response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
        ], $e->status));

        $exceptions->render(fn (CapabilityUnavailable $e) => response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
        ], 422));

        $exceptions->render(function (AiException $e) {
            Log::warning('ai.error', ['code' => $e->errorCode, 'message' => $e->getMessage()]);

            return response()->json([
                'message' => 'The AI service is temporarily unavailable. Please try again shortly.',
                'code' => 'ai_unavailable',
            ], 503);
        });
    })->create();
