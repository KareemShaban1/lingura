<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\BillingService;
use App\Domain\Billing\EntitlementService;
use App\Domain\Billing\InvalidWebhook;
use App\Domain\Billing\QuotaGuard;
use App\Domain\Shared\DomainError;
use App\Http\Controllers\Controller;
use App\Models\LearnerProfile;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing, private readonly EntitlementService $entitlements, private readonly QuotaGuard $quota) {}

    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::where('is_active', true)->where('is_public', true)->with('entitlements')->orderBy('rank')->orderBy('price_minor')->get();

        return response()->json(['data' => $plans->map(fn (SubscriptionPlan $p) => self::plan($p))->values(), 'meta' => [
            'checkout_available' => config('billing.driver') === 'fake' || filled(config('billing.stripe.secret')),
        ]]);
    }

    public function subscription(Request $request): JsonResponse
    {
        $user = $request->user();
        $resolved = $this->entitlements->resolve($user);
        $subscription = $resolved['subscription'];
        $canManage = $subscription && $subscription->user_id === $user->id;

        return response()->json(['data' => [
            'plan' => self::plan($resolved['plan']->loadMissing('entitlements')),
            'source' => $resolved['source'],
            'subscription' => $subscription ? [
                'id' => $subscription->public_id,
                'status' => $subscription->status,
                'provider' => $subscription->provider,
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'organization' => $subscription->organization ? ['id' => $subscription->organization->public_id, 'name' => $subscription->organization->name] : null,
                'can_manage' => $canManage,
            ] : null,
            'usage' => $this->quota->summary($user, fn (string $feature) => $feature === 'target_languages' ? self::activeLanguages($user) : 0),
            'portal_available' => $canManage && $subscription->provider === 'stripe',
            'driver' => config('billing.driver'),
        ]]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $slug = $request->validate(['plan' => ['required', 'string', 'exists:subscription_plans,slug']])['plan'];
        $session = $this->billing->checkout($request->user(), SubscriptionPlan::where('slug', $slug)->firstOrFail());

        return response()->json(['data' => ['url' => $session->url]]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $id = $request->validate(['session_id' => ['required', 'string', 'max:120']])['session_id'];
        if (! str_starts_with($id, 'fake_cs_')) {
            // Paid checkouts are confirmed by the provider's webhook; the return page only polls.
            return response()->json(['data' => ['status' => 'pending']]);
        }
        $subscription = $this->billing->confirmFakeCheckout($request->user(), $id);

        return response()->json(['data' => ['status' => $subscription->status]]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $this->billing->setCancelAtPeriodEnd($this->owned($request->user()), true);

        return $this->subscription($request);
    }

    public function resume(Request $request): JsonResponse
    {
        $this->billing->setCancelAtPeriodEnd($this->owned($request->user()), false);

        return $this->subscription($request);
    }

    public function portal(Request $request): JsonResponse
    {
        $url = $this->billing->portalUrl($request->user());
        if (! $url) {
            throw new DomainError('There is no billing portal for your plan.', 'portal_unavailable', 404);
        }

        return response()->json(['data' => ['url' => $url]]);
    }

    public function payments(Request $request): JsonResponse
    {
        $payments = Payment::where('user_id', $request->user()->id)->with('subscription.plan')->latest('id')->limit(50)->get();

        return response()->json(['data' => $payments->map(fn (Payment $p) => [
            'id' => $p->id,
            'amount_minor' => $p->amount_minor,
            'currency' => $p->currency,
            'status' => $p->status,
            'plan' => $p->subscription?->plan?->slug,
            'invoice_url' => $p->hosted_invoice_url,
            'date' => ($p->paid_at ?? $p->created_at)->toIso8601String(),
        ])]);
    }

    public function webhook(Request $request): JsonResponse
    {
        try {
            $result = $this->billing->handleWebhook($request->getContent(), $request->header('Stripe-Signature'));
        } catch (InvalidWebhook $e) {
            Log::warning('billing.webhook_rejected', ['reason' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        return response()->json(['received' => true, 'result' => $result]);
    }

    private function owned(User $user): Subscription
    {
        $subscription = $this->entitlements->resolve($user)['subscription'];
        if (! $subscription || $subscription->user_id !== $user->id) {
            throw new DomainError('You have no subscription to manage.', 'no_subscription', 404);
        }

        return $subscription;
    }

    public static function activeLanguages(User $user): int
    {
        return LearnerProfile::where('user_id', $user->id)->where('status', '!=', LearnerProfile::STATUS_ARCHIVED)->count();
    }

    /** @return array<string, mixed> */
    public static function plan(SubscriptionPlan $p): array
    {
        return [
            'slug' => $p->slug,
            'tier' => $p->tier,
            'interval' => $p->interval,
            'price_minor' => $p->price_minor,
            'currency' => $p->currency,
            'seats' => $p->seats,
            'limits' => $p->entitlements->mapWithKeys(fn ($e) => [$e->feature => $e->limit === null ? null : (int) $e->limit]),
        ];
    }
}
