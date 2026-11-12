<?php

namespace App\Providers;

use App\Domain\AI\AiManager;
use App\Domain\Billing\Contracts\BillingGateway;
use App\Domain\Billing\EntitlementService;
use App\Domain\Billing\Gateways\FakeGateway;
use App\Domain\Billing\Gateways\StripeGateway;
use App\Domain\Languages\CapabilityMatrix;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiManager::class);
        $this->app->singleton(CapabilityMatrix::class);
        // Per request / per queued job, so plan changes are never served from a stale memo.
        $this->app->scoped(EntitlementService::class);
        $this->app->singleton(BillingGateway::class, function ($app) {
            return match (config('billing.driver')) {
                'stripe' => new StripeGateway(config('billing.stripe')),
                'fake' => $app->isProduction()
                    ? throw new RuntimeException('The fake billing driver cannot be used in production.')
                    : new FakeGateway,
                default => throw new RuntimeException('Unknown billing driver ['.config('billing.driver').'].'),
            };
        });
    }

    public function boot(): void
    {
        Password::defaults(fn () => Password::min(10)->letters()->numbers()
            ->when($this->app->isProduction(), fn ($rule) => $rule->uncompromised()));

        ResetPassword::createUrlUsing(fn (User $user, string $token) => rtrim(config('app.frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($user->email));

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));
        // AI-backed calls cost money: a tighter per-user limit on top of daily quotas.
        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('assessment', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        Gate::define('access-admin', fn (User $user) => $user->hasPermission('admin.access'));
    }
}
