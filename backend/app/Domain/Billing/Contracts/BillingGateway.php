<?php

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\CheckoutSession;
use App\Domain\Billing\InvalidWebhook;
use App\Domain\Billing\ProviderSubscription;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;

/** Payment-provider boundary. Business logic never talks to Stripe (or any provider) directly. */
interface BillingGateway
{
    public function name(): string;

    /** @param array<string, string> $metadata copied onto the provider subscription so webhooks can be matched */
    public function createCheckout(User $user, SubscriptionPlan $plan, array $metadata, string $successUrl, string $cancelUrl): CheckoutSession;

    /** Hosted page for payment methods and invoices; null when the provider has none. */
    public function portalUrl(User $user, string $returnUrl): ?string;

    public function setCancelAtPeriodEnd(Subscription $subscription, bool $cancel): ProviderSubscription;

    public function fetchSubscription(string $providerSubscriptionId): ProviderSubscription;

    /**
     * Verifies the signature and returns the decoded event.
     *
     * @return array{id: string, type: string, data: array{object: array<string, mixed>}}
     *
     * @throws InvalidWebhook
     */
    public function parseWebhook(string $payload, ?string $signature): array;
}
