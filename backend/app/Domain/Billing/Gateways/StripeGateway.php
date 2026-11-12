<?php

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\CheckoutSession;
use App\Domain\Billing\Contracts\BillingGateway;
use App\Domain\Billing\InvalidWebhook;
use App\Domain\Billing\ProviderSubscription;
use App\Domain\Shared\DomainError;
use App\Models\BillingCustomer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Stripe over its REST API (form-encoded). The secret key never leaves the backend. */
class StripeGateway implements BillingGateway
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function createCheckout(User $user, SubscriptionPlan $plan, array $metadata, string $successUrl, string $cancelUrl): CheckoutSession
    {
        if (! $plan->provider_price_id) {
            throw new DomainError('This plan is not available for purchase yet.', 'plan_unavailable', 422);
        }
        $response = $this->send('post', '/v1/checkout/sessions', [
            'mode' => 'subscription',
            'customer' => $this->customerId($user),
            'client_reference_id' => (string) $user->id,
            'line_items' => [['price' => $plan->provider_price_id, 'quantity' => 1]],
            'success_url' => $successUrl.(str_contains($successUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'allow_promotion_codes' => 'true',
            'metadata' => $metadata,
            'subscription_data' => ['metadata' => $metadata],
        ]);

        return new CheckoutSession($response['id'], $response['url']);
    }

    public function portalUrl(User $user, string $returnUrl): ?string
    {
        $customer = BillingCustomer::where('user_id', $user->id)->where('provider', 'stripe')->value('provider_customer_id');
        if (! $customer) {
            return null;
        }

        return $this->send('post', '/v1/billing_portal/sessions', ['customer' => $customer, 'return_url' => $returnUrl])['url'];
    }

    public function setCancelAtPeriodEnd(Subscription $subscription, bool $cancel): ProviderSubscription
    {
        return $this->map($this->send('post', "/v1/subscriptions/{$subscription->provider_subscription_id}", [
            'cancel_at_period_end' => $cancel ? 'true' : 'false',
        ]));
    }

    public function fetchSubscription(string $providerSubscriptionId): ProviderSubscription
    {
        return $this->map($this->send('get', "/v1/subscriptions/{$providerSubscriptionId}"));
    }

    public function parseWebhook(string $payload, ?string $signature): array
    {
        $secret = $this->config['webhook_secret'] ?? null;
        if (! $secret || ! $signature) {
            throw new InvalidWebhook('Missing signature or webhook secret.');
        }
        $parts = [];
        foreach (explode(',', $signature) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$k][] = $v;
        }
        $timestamp = (int) ($parts['t'][0] ?? 0);
        if (abs(time() - $timestamp) > ($this->config['webhook_tolerance_seconds'] ?? 300)) {
            throw new InvalidWebhook('Signature timestamp outside tolerance.');
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $valid = collect($parts['v1'] ?? [])->contains(fn ($sig) => hash_equals($expected, $sig));
        if (! $valid) {
            throw new InvalidWebhook('Signature mismatch.');
        }
        $event = json_decode($payload, true);
        if (! is_array($event) || ! isset($event['id'], $event['type'], $event['data']['object'])) {
            throw new InvalidWebhook('Malformed event.');
        }

        return $event;
    }

    private function customerId(User $user): string
    {
        $existing = BillingCustomer::where('user_id', $user->id)->where('provider', 'stripe')->value('provider_customer_id');
        if ($existing) {
            return $existing;
        }
        $customer = $this->send('post', '/v1/customers', [
            'email' => $user->email, 'name' => $user->name, 'metadata' => ['user_id' => (string) $user->id],
        ]);
        BillingCustomer::create(['user_id' => $user->id, 'provider' => 'stripe', 'provider_customer_id' => $customer['id']]);

        return $customer['id'];
    }

    /** @param array<string, mixed> $s */
    private function map(array $s): ProviderSubscription
    {
        // Newer API versions moved billing periods onto subscription items.
        $item = $s['items']['data'][0] ?? [];
        $ts = fn ($v) => $v ? Carbon::createFromTimestamp((int) $v) : null;

        return new ProviderSubscription(
            id: $s['id'],
            customerId: is_array($s['customer'] ?? null) ? $s['customer']['id'] : (string) ($s['customer'] ?? ''),
            status: (string) $s['status'],
            priceId: $item['price']['id'] ?? null,
            periodStart: $ts($item['current_period_start'] ?? $s['current_period_start'] ?? null),
            periodEnd: $ts($item['current_period_end'] ?? $s['current_period_end'] ?? null),
            cancelAtPeriodEnd: (bool) ($s['cancel_at_period_end'] ?? false),
            canceledAt: $ts($s['canceled_at'] ?? null),
            endedAt: $ts($s['ended_at'] ?? null),
            metadata: array_map('strval', $s['metadata'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $path, array $data = []): array
    {
        if (empty($this->config['secret'])) {
            throw new DomainError('Payments are not configured.', 'billing_unavailable', 503);
        }
        /** @var Response $response */
        $response = $this->http($method)->{$method}($path, $data);
        if ($response->failed()) {
            Log::warning('stripe.error', ['path' => $path, 'status' => $response->status(), 'type' => $response->json('error.type')]);
            throw new DomainError('The payment provider could not process this request. Please try again.', 'billing_provider_error', 502);
        }

        return $response->json();
    }

    private function http(string $method): PendingRequest
    {
        $request = Http::baseUrl($this->config['api_base'] ?? 'https://api.stripe.com')
            ->withToken($this->config['secret'])
            ->asForm()
            ->acceptJson()
            ->timeout($this->config['timeout'] ?? 20);

        // Only reads are retried: a retried create could double-charge or duplicate customers.
        return $method === 'get' ? $request->retry(2, 300, throw: false) : $request;
    }
}
