<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Contracts\BillingGateway;
use App\Domain\Billing\Gateways\FakeGateway;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainError;
use App\Models\BillingCustomer;
use App\Models\BillingEvent;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\AccountNotice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Subscription lifecycle. The provider is the source of truth for paid subscriptions: every webhook
 * re-fetches the subscription and syncs it, so out-of-order or repeated events converge to the same state.
 */
class BillingService
{
    public function __construct(
        private readonly BillingGateway $gateway,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function checkout(User $user, SubscriptionPlan $plan): CheckoutSession
    {
        if (! $plan->is_active || ! $plan->is_public || $plan->tier === 'free') {
            throw new DomainError('This plan cannot be purchased.', 'plan_unavailable', 422);
        }
        $organization = null;
        if ($plan->seats > 1) {
            $organization = Organization::where('owner_id', $user->id)->where('type', 'family')->first();
            if ($organization?->activeSubscription()) {
                throw new DomainError('Your family group already has an active plan.', 'already_subscribed', 409);
            }
        } elseif ($this->livePersonal($user)) {
            throw new DomainError('You already have an active plan. Manage it from the billing page.', 'already_subscribed', 409);
        }

        $organization ??= $plan->seats > 1 ? $this->createFamily($user) : null;
        $base = rtrim(config('app.frontend_url'), '/');
        $metadata = array_filter(['user_id' => (string) $user->id, 'plan' => $plan->slug, 'organization_id' => $organization ? (string) $organization->id : null]);
        $this->audit->log('billing.checkout_started', $plan, new: ['plan' => $plan->slug], actorId: $user->id);

        return $this->gateway->createCheckout($user, $plan, $metadata, "{$base}/billing/return", "{$base}/pricing?canceled=1");
    }

    /** Local development only: completes a fake checkout as a webhook would. */
    public function confirmFakeCheckout(User $user, string $sessionId): Subscription
    {
        if (! $this->gateway instanceof FakeGateway) {
            throw new DomainError('Checkout confirmation happens through the payment provider.', 'not_supported', 404);
        }
        $session = $this->gateway->pullCheckout($sessionId, $user);
        $plan = SubscriptionPlan::findOrFail($session['plan_id']);
        $start = now();

        return $this->sync(new ProviderSubscription(
            id: 'fake_sub_'.Str::lower(Str::random(20)), customerId: 'fake_cus_'.$user->id, status: 'active', priceId: null,
            periodStart: $start, periodEnd: $plan->interval === 'year' ? $start->copy()->addYear() : $start->copy()->addMonth(),
            cancelAtPeriodEnd: false, canceledAt: null, endedAt: null, metadata: $session['metadata'],
        ), 'fake');
    }

    public function sync(ProviderSubscription $remote, string $provider): Subscription
    {
        return DB::transaction(function () use ($remote, $provider) {
            $subscription = Subscription::where('provider_subscription_id', $remote->id)->lockForUpdate()->first();
            $plan = ($remote->priceId ? SubscriptionPlan::where('provider_price_id', $remote->priceId)->first() : null)
                ?? (isset($remote->metadata['plan']) ? SubscriptionPlan::where('slug', $remote->metadata['plan'])->first() : null)
                ?? $subscription?->plan
                ?? throw new DomainError("No plan matches provider price [{$remote->priceId}].", 'plan_unknown', 422);

            $before = $subscription ? ['status' => $subscription->status, 'cancel_at_period_end' => $subscription->cancel_at_period_end, 'plan' => $subscription->plan->slug] : null;
            $attributes = [
                'subscription_plan_id' => $plan->id,
                'status' => $this->normaliseStatus($remote->status),
                'current_period_start' => $remote->periodStart,
                'current_period_end' => $remote->periodEnd,
                'cancel_at_period_end' => $remote->cancelAtPeriodEnd,
                'canceled_at' => $remote->canceledAt,
                'ended_at' => $remote->endedAt ?? ($remote->status === 'canceled' ? now() : null),
            ];

            if (! $subscription) {
                $userId = (int) ($remote->metadata['user_id'] ?? 0)
                    ?: (int) BillingCustomer::where('provider', $provider)->where('provider_customer_id', $remote->customerId)->value('user_id');
                $user = User::find($userId) ?? throw new DomainError('Subscription owner not found.', 'owner_unknown', 422);
                $subscription = Subscription::create($attributes + [
                    'user_id' => $user->id,
                    'organization_id' => isset($remote->metadata['organization_id']) ? (int) $remote->metadata['organization_id'] : null,
                    'provider' => $provider,
                    'provider_subscription_id' => $remote->id,
                ]);
            } else {
                $subscription->update($attributes);
            }
            if ($provider !== 'fake' && $remote->customerId) {
                BillingCustomer::firstOrCreate(['user_id' => $subscription->user_id, 'provider' => $provider], ['provider_customer_id' => $remote->customerId]);
            }

            $this->afterChange($subscription->fresh(['plan', 'user']), $before);

            return $subscription;
        });
    }

    /** @return 'processed'|'duplicate'|'ignored' */
    public function handleWebhook(string $payload, ?string $signature): string
    {
        $event = $this->gateway->parseWebhook($payload, $signature);
        $provider = $this->gateway->name();

        $record = BillingEvent::firstOrCreate(
            ['provider' => $provider, 'event_id' => $event['id']],
            ['type' => $event['type'], 'payload' => $event],
        );
        if (in_array($record->status, ['processed', 'ignored'], true)) {
            return 'duplicate';
        }

        try {
            $object = $event['data']['object'];
            $handled = match (true) {
                $event['type'] === 'checkout.session.completed' => $this->onCheckoutCompleted($object, $provider),
                str_starts_with($event['type'], 'customer.subscription.') => $this->sync($this->gateway->fetchSubscription($object['id']), $provider) !== null,
                in_array($event['type'], ['invoice.paid', 'invoice.payment_succeeded', 'invoice.payment_failed'], true) => $this->onInvoice($object, $event['type'], $provider),
                default => false,
            };
            $record->update(['status' => $handled ? 'processed' : 'ignored', 'processed_at' => now(), 'error' => null]);

            return $handled ? 'processed' : 'ignored';
        } catch (Throwable $e) {
            $record->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 1000)]);
            throw $e; // non-2xx so the provider retries
        }
    }

    public function setCancelAtPeriodEnd(Subscription $subscription, bool $cancel): Subscription
    {
        if (! $subscription->isLive()) {
            throw new DomainError('This subscription is no longer active.', 'subscription_inactive', 409);
        }
        if ($subscription->provider === 'manual') {
            $before = ['status' => $subscription->status, 'cancel_at_period_end' => $subscription->cancel_at_period_end, 'plan' => $subscription->plan->slug];
            $subscription->update(['cancel_at_period_end' => $cancel]);
            $this->afterChange($subscription->fresh(['plan', 'user']), $before);

            return $subscription;
        }

        return $this->sync($this->gateway->setCancelAtPeriodEnd($subscription, $cancel), $subscription->provider);
    }

    public function portalUrl(User $user): ?string
    {
        return $this->gateway->portalUrl($user, rtrim(config('app.frontend_url'), '/').'/billing');
    }

    /** Complimentary access granted by staff (support cases, partners). Always time-boxed. */
    public function grantManual(User $user, SubscriptionPlan $plan, Carbon $until, string $note, User $actor): Subscription
    {
        if ($plan->seats > 1) {
            throw new DomainError('Seat plans cannot be granted manually.', 'plan_unavailable', 422);
        }
        $subscription = Subscription::create([
            'user_id' => $user->id, 'subscription_plan_id' => $plan->id, 'provider' => 'manual', 'status' => 'active',
            'current_period_start' => now(), 'current_period_end' => $until, 'cancel_at_period_end' => true, 'note' => $note,
        ]);
        $this->audit->log('billing.manual_grant', $subscription, new: ['plan' => $plan->slug, 'until' => $until->toIso8601String(), 'note' => $note], actorId: $actor->id);
        $this->afterChange($subscription->fresh(['plan', 'user']), null);

        return $subscription;
    }

    public function endNow(Subscription $subscription, User $actor): void
    {
        if ($subscription->provider !== 'manual') {
            throw new DomainError('Paid subscriptions are cancelled through the payment provider.', 'not_supported', 422);
        }
        $before = ['status' => $subscription->status, 'cancel_at_period_end' => $subscription->cancel_at_period_end, 'plan' => $subscription->plan->slug];
        $subscription->update(['status' => 'canceled', 'canceled_at' => now(), 'ended_at' => now()]);
        $this->audit->log('billing.manual_revoked', $subscription, actorId: $actor->id);
        $this->afterChange($subscription->fresh(['plan', 'user']), $before);
    }

    /** Ends lapsed manual grants and rolls fake subscriptions (local only). Paid ones follow provider webhooks. */
    public function reconcile(): int
    {
        $count = 0;
        Subscription::whereIn('provider', ['manual', 'fake'])->whereIn('status', Subscription::LIVE_STATUSES)
            ->where('current_period_end', '<=', now())->with('plan', 'user')->each(function (Subscription $s) use (&$count) {
                $before = ['status' => $s->status, 'cancel_at_period_end' => $s->cancel_at_period_end, 'plan' => $s->plan->slug];
                if ($s->cancel_at_period_end || $s->provider === 'manual') {
                    $s->update(['status' => 'canceled', 'ended_at' => now()]);
                } else {
                    $start = $s->current_period_end;
                    $s->update(['current_period_start' => $start, 'current_period_end' => $s->plan->interval === 'year' ? $start->copy()->addYear() : $start->copy()->addMonth()]);
                }
                $this->afterChange($s->fresh(['plan', 'user']), $before);
                $count++;
            });

        return $count;
    }

    public function livePersonal(User $user): ?Subscription
    {
        return Subscription::where('user_id', $user->id)->whereNull('organization_id')
            ->whereIn('status', Subscription::LIVE_STATUSES)->with('plan')->latest('id')->first();
    }

    private function createFamily(User $user): Organization
    {
        $organization = Organization::create(['name' => $user->name.'’s family', 'type' => 'family', 'owner_id' => $user->id]);
        OrganizationMember::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'owner', 'joined_at' => now()]);

        return $organization;
    }

    private function onCheckoutCompleted(array $session, string $provider): bool
    {
        if (empty($session['subscription'])) {
            return false;
        }
        $userId = (int) ($session['metadata']['user_id'] ?? $session['client_reference_id'] ?? 0);
        if ($userId && ! empty($session['customer'])) {
            BillingCustomer::firstOrCreate(['user_id' => $userId, 'provider' => $provider], ['provider_customer_id' => $session['customer']]);
        }
        $this->sync($this->gateway->fetchSubscription(is_array($session['subscription']) ? $session['subscription']['id'] : $session['subscription']), $provider);

        return true;
    }

    private function onInvoice(array $invoice, string $type, string $provider): bool
    {
        $subscriptionId = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? null;
        $subscription = $subscriptionId ? Subscription::where('provider_subscription_id', $subscriptionId)->with('plan')->first() : null;
        $userId = $subscription?->user_id
            ?? BillingCustomer::where('provider', $provider)->where('provider_customer_id', $invoice['customer'] ?? '')->value('user_id');
        if (! $userId) {
            return false;
        }
        $failed = $type === 'invoice.payment_failed';
        $payment = Payment::updateOrCreate(['provider_invoice_id' => $invoice['id']], [
            'subscription_id' => $subscription?->id,
            'user_id' => $userId,
            'provider' => $provider,
            'amount_minor' => (int) ($failed ? ($invoice['amount_due'] ?? 0) : ($invoice['amount_paid'] ?? 0)),
            'currency' => strtoupper((string) ($invoice['currency'] ?? 'usd')),
            'status' => $failed ? 'failed' : 'paid',
            'hosted_invoice_url' => $invoice['hosted_invoice_url'] ?? null,
            'paid_at' => $failed ? null : Carbon::createFromTimestamp((int) ($invoice['status_transitions']['paid_at'] ?? time())),
        ]);
        if ($payment->wasRecentlyCreated || $payment->wasChanged('status')) {
            User::find($userId)?->notify(new AccountNotice($failed ? 'payment_failed' : 'payment_succeeded', ['plan' => $subscription?->plan?->slug]));
        }

        return true;
    }

    /** @param array{status: string, cancel_at_period_end: bool, plan: string}|null $before */
    private function afterChange(Subscription $subscription, ?array $before): void
    {
        $this->entitlements->forget();
        $wasLive = $before && in_array($before['status'], Subscription::LIVE_STATUSES, true);
        $data = ['plan' => $subscription->plan->slug, 'date' => $subscription->current_period_end?->toDateString()];

        $kind = match (true) {
            ! $wasLive && $subscription->isLive() => 'subscription_activated',
            $wasLive && ! $subscription->isLive() => 'subscription_ended',
            $wasLive && ! $before['cancel_at_period_end'] && $subscription->cancel_at_period_end && $subscription->provider !== 'manual' => 'subscription_cancel_scheduled',
            $wasLive && $before['cancel_at_period_end'] && ! $subscription->cancel_at_period_end => 'subscription_resumed',
            default => null,
        };
        if ($kind) {
            $subscription->user->notify(new AccountNotice($kind, $data));
            $this->audit->log("billing.{$kind}", $subscription, old: $before ?? [], new: ['status' => $subscription->status, 'plan' => $subscription->plan->slug]);
        }
    }

    private function normaliseStatus(string $status): string
    {
        return match ($status) {
            'trialing', 'active', 'past_due', 'canceled', 'unpaid', 'incomplete' => $status,
            'incomplete_expired' => 'canceled',
            'paused' => 'unpaid',
            default => 'incomplete',
        };
    }
}
