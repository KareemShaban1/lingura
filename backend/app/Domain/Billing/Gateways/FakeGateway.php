<?php

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\CheckoutSession;
use App\Domain\Billing\Contracts\BillingGateway;
use App\Domain\Billing\InvalidWebhook;
use App\Domain\Billing\ProviderSubscription;
use App\Domain\Shared\DomainError;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Local/testing gateway: no money moves. Checkout redirects straight back to the app, which confirms
 * the session (`POST /billing/checkout/confirm`). Refused in production by BillingServiceProvider.
 */
class FakeGateway implements BillingGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function createCheckout(User $user, SubscriptionPlan $plan, array $metadata, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $id = 'fake_cs_'.Str::lower(Str::random(24));
        Cache::put("fake-checkout:{$id}", ['user_id' => $user->id, 'plan_id' => $plan->id, 'metadata' => $metadata], now()->addHour());

        return new CheckoutSession($id, $successUrl.(str_contains($successUrl, '?') ? '&' : '?').'session_id='.$id);
    }

    /** @return array{user_id: int, plan_id: int, metadata: array<string, string>} */
    public function pullCheckout(string $sessionId, User $user): array
    {
        $session = Cache::get("fake-checkout:{$sessionId}");
        if (! $session || $session['user_id'] !== $user->id) {
            throw new DomainError('This checkout session is invalid or has expired.', 'checkout_invalid', 422);
        }
        Cache::forget("fake-checkout:{$sessionId}"); // single use, consumed only by its owner

        return $session;
    }

    public function portalUrl(User $user, string $returnUrl): ?string
    {
        return null;
    }

    public function setCancelAtPeriodEnd(Subscription $subscription, bool $cancel): ProviderSubscription
    {
        $snapshot = $this->fetchSubscription($subscription->provider_subscription_id);

        return new ProviderSubscription($snapshot->id, $snapshot->customerId, $snapshot->status, $snapshot->priceId,
            $snapshot->periodStart, $snapshot->periodEnd, $cancel, $snapshot->canceledAt, $snapshot->endedAt, $snapshot->metadata);
    }

    public function fetchSubscription(string $providerSubscriptionId): ProviderSubscription
    {
        $s = Subscription::where('provider_subscription_id', $providerSubscriptionId)->with('plan')->firstOrFail();

        return new ProviderSubscription($s->provider_subscription_id, 'fake_cus_'.$s->user_id, $s->status, $s->plan->provider_price_id ?? 'fake_'.$s->plan->slug,
            $s->current_period_start, $s->current_period_end, $s->cancel_at_period_end, $s->canceled_at, $s->ended_at);
    }

    public function parseWebhook(string $payload, ?string $signature): array
    {
        throw new InvalidWebhook('The fake billing driver does not accept webhooks.');
    }
}
