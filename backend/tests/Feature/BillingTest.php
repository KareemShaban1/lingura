<?php

namespace Tests\Feature;

use App\Domain\AI\Drivers\FakeDriver;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\Contracts\BillingGateway;
use App\Domain\Billing\EntitlementService;
use App\Domain\Billing\QuotaGuard;
use App\Models\BillingEvent;
use App\Models\Payment;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\AccountNotice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BillingTest extends TestCase
{
    public function test_public_plans_list_prices_and_limits(): void
    {
        $plans = collect($this->getJson('/api/v1/billing/plans')->assertOk()->json('data'));

        $this->assertCount(7, $plans);
        $free = $plans->firstWhere('slug', 'free');
        $this->assertSame(0, $free['limits']['realtime_minutes']);
        $this->assertSame(1, $free['limits']['target_languages']);
        $this->assertNull($plans->firstWhere('slug', 'pro_monthly')['limits']['target_languages'], 'unlimited');
        $this->assertSame(6, $plans->firstWhere('slug', 'family_yearly')['seats']);
    }

    public function test_fake_checkout_cancel_and_resume_with_notifications(): void
    {
        Notification::fake();
        $user = $this->actingAsUser($this->makeUser());

        $this->getJson('/api/v1/billing/subscription')->assertOk()
            ->assertJsonPath('data.plan.slug', 'free')->assertJsonPath('data.source', 'free');

        $url = $this->postJson('/api/v1/billing/checkout', ['plan' => 'premium_monthly'])->assertOk()->json('data.url');
        $this->assertStringContainsString('/billing/return?session_id=fake_cs_', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        // Another user cannot redeem the session.
        $other = $this->makeUser();
        $this->actingAsUser($other);
        $this->postJson('/api/v1/billing/checkout/confirm', ['session_id' => $query['session_id']])->assertStatus(422);

        $this->actingAsUser($user);
        $this->postJson('/api/v1/billing/checkout/confirm', ['session_id' => $query['session_id']])->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson('/api/v1/billing/checkout/confirm', ['session_id' => $query['session_id']])->assertStatus(422); // single use

        $data = $this->getJson('/api/v1/billing/subscription')->assertOk()->json('data');
        $this->assertSame('premium_monthly', $data['plan']['slug']);
        $this->assertSame('personal', $data['source']);
        $this->assertTrue($data['subscription']['can_manage']);
        $usage = collect($data['usage'])->keyBy('feature');
        $this->assertSame(150, $usage['tutor_messages']['limit']);
        Notification::assertSentTo($user, AccountNotice::class, fn ($n) => $n->kind === 'subscription_activated');

        $this->postJson('/api/v1/billing/checkout', ['plan' => 'pro_monthly'])->assertStatus(409)->assertJsonPath('code', 'already_subscribed');

        $this->postJson('/api/v1/billing/subscription/cancel')->assertOk()->assertJsonPath('data.subscription.cancel_at_period_end', true);
        Notification::assertSentTo($user, AccountNotice::class, fn ($n) => $n->kind === 'subscription_cancel_scheduled');
        $this->postJson('/api/v1/billing/subscription/resume')->assertOk()->assertJsonPath('data.subscription.cancel_at_period_end', false);
        Notification::assertSentTo($user, AccountNotice::class, fn ($n) => $n->kind === 'subscription_resumed');
    }

    public function test_quota_is_enforced_atomically_and_refunded_when_the_ai_call_fails(): void
    {
        $profile = $this->makeLearner('es');
        $user = $this->actingAsUser($profile->user);
        PlanEntitlement::whereHas('plan', fn ($q) => $q->where('slug', 'free'))->where('feature', 'tutor_messages')->update(['limit' => 2]);
        app(EntitlementService::class)->forget();

        FakeDriver::queueText(['reply' => '¡Hola!', 'corrections' => [], 'natural_alternative' => '', 'new_vocabulary' => []]);
        $conversation = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/conversations", ['tutor' => 'lina', 'mode' => 'text'])
            ->assertCreated()->json('data.id');

        // A failed AI call (invalid output) does not consume the turn.
        FakeDriver::queueText('this is not json');
        $this->postJson("/api/v1/conversations/{$conversation}/messages", ['text' => 'Hola'])->assertStatus(503);
        $this->assertSame(0, app(QuotaGuard::class)->used($user, 'tutor_messages'));

        foreach (['Uno', 'Dos'] as $text) {
            FakeDriver::queueText(['reply' => 'Vale.', 'corrections' => [], 'natural_alternative' => '', 'new_vocabulary' => []]);
            $this->postJson("/api/v1/conversations/{$conversation}/messages", ['text' => $text])->assertOk();
        }
        $this->postJson("/api/v1/conversations/{$conversation}/messages", ['text' => 'Tres'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'quota_exceeded')
            ->assertJsonPath('details.feature', 'tutor_messages')
            ->assertJsonPath('details.limit', 2)
            ->assertJsonPath('details.plan', 'free');

        // Free plan: realtime voice is not included; one target language only.
        $this->postJson("/api/v1/conversations/{$conversation}/realtime-session")->assertStatus(429)->assertJsonPath('details.feature', 'realtime_minutes');
        $this->postJson('/api/v1/learner-profiles', ['target_language' => 'de', 'purpose' => 'travel', 'daily_minutes' => 15])
            ->assertStatus(429)->assertJsonPath('details.feature', 'target_languages');
    }

    public function test_realtime_minutes_are_charged_in_blocks(): void
    {
        $profile = $this->makeLearner('es');
        $user = $this->actingAsUser($profile->user);
        $this->subscribe($user, 'premium_monthly');
        PlanEntitlement::whereHas('plan', fn ($q) => $q->where('slug', 'premium_monthly'))->where('feature', 'realtime_minutes')->update(['limit' => 7]);
        app(EntitlementService::class)->forget();
        FakeDriver::queueText(['reply' => '¡Hola!', 'corrections' => [], 'natural_alternative' => '', 'new_vocabulary' => []]);
        $conversation = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/conversations", ['tutor' => 'lina', 'mode' => 'text'])->json('data.id');

        $this->postJson("/api/v1/conversations/{$conversation}/realtime-session")->assertOk()->assertJsonPath('data.max_seconds', 300);
        $this->postJson("/api/v1/conversations/{$conversation}/realtime-session")->assertOk()->assertJsonPath('data.max_seconds', 120); // last 2 minutes
        $this->postJson("/api/v1/conversations/{$conversation}/realtime-session")->assertStatus(429);
        $this->assertSame(7, app(QuotaGuard::class)->used($user, 'realtime_minutes'));
    }

    private function useStripe(): void
    {
        config(['billing.driver' => 'stripe', 'billing.stripe.secret' => 'sk_test_x', 'billing.stripe.webhook_secret' => 'whsec_test']);
        app()->forgetInstance(BillingGateway::class);
        app()->forgetInstance(BillingService::class);
        SubscriptionPlan::where('slug', 'premium_monthly')->update(['provider_price_id' => 'price_premium_m']);
    }

    private function signed(array $event): array
    {
        $payload = json_encode($event);
        $t = time();

        return [$payload, ['Stripe-Signature' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", 'whsec_test'), 'Content-Type' => 'application/json']];
    }

    private function stripeSubscription(User $user, string $status = 'active', bool $cancel = false): array
    {
        return [
            'id' => 'sub_123', 'customer' => 'cus_123', 'status' => $status, 'cancel_at_period_end' => $cancel,
            'canceled_at' => null, 'ended_at' => null, 'metadata' => ['user_id' => (string) $user->id, 'plan' => 'premium_monthly'],
            'items' => ['data' => [['price' => ['id' => 'price_premium_m'], 'current_period_start' => time(), 'current_period_end' => time() + 30 * 86400]]],
        ];
    }

    public function test_stripe_checkout_creates_customer_and_session_server_side(): void
    {
        $this->useStripe();
        $user = $this->actingAsUser($this->makeUser());
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_123']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
        ]);

        $this->postJson('/api/v1/billing/checkout', ['plan' => 'premium_monthly'])->assertOk()
            ->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/cs_1');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/checkout/sessions')
            && $r['line_items'][0]['price'] === 'price_premium_m'
            && $r['subscription_data']['metadata']['user_id'] === (string) $user->id
            && $r['customer'] === 'cus_123'
            && $r->hasHeader('Authorization', 'Bearer sk_test_x'));
        $this->assertDatabaseHas('billing_customers', ['user_id' => $user->id, 'provider_customer_id' => 'cus_123']);
    }

    public function test_stripe_webhooks_are_verified_idempotent_and_refetch_state(): void
    {
        Notification::fake();
        $this->useStripe();
        $user = $this->makeUser();
        $remote = 'active'; // what the provider currently reports
        Http::fake(['api.stripe.com/v1/subscriptions/sub_123' => function () use (&$remote, $user) {
            return Http::response($this->stripeSubscription($user, $remote));
        }]);

        $event = ['id' => 'evt_1', 'type' => 'customer.subscription.created', 'data' => ['object' => ['id' => 'sub_123']]];
        [$payload, $headers] = $this->signed($event);

        // Tampered payload → rejected, nothing stored.
        $this->call('POST', '/api/v1/webhooks/billing', [], [], [], $this->server($headers), str_replace('evt_1', 'evt_X', $payload))->assertStatus(400);
        $this->assertSame(0, BillingEvent::count());

        $this->call('POST', '/api/v1/webhooks/billing', [], [], [], $this->server($headers), $payload)->assertOk()->assertJsonPath('result', 'processed');
        $subscription = Subscription::where('provider_subscription_id', 'sub_123')->firstOrFail();
        $this->assertSame('active', $subscription->status);
        $this->assertSame($user->id, $subscription->user_id);
        $this->assertSame('premium_monthly', app(EntitlementService::class)->resolve($user)['plan']->slug);

        // Replays are acknowledged without reprocessing.
        $this->call('POST', '/api/v1/webhooks/billing', [], [], [], $this->server($headers), $payload)->assertOk()->assertJsonPath('result', 'duplicate');

        // Failed renewal payment: recorded and the learner is told.
        [$p2, $h2] = $this->signed(['id' => 'evt_2', 'type' => 'invoice.payment_failed', 'data' => ['object' => [
            'id' => 'in_1', 'customer' => 'cus_123', 'subscription' => 'sub_123', 'amount_due' => 1299, 'currency' => 'usd', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/1',
        ]]]);
        $this->call('POST', '/api/v1/webhooks/billing', [], [], [], $this->server($h2), $p2)->assertOk();
        $this->assertSame('failed', Payment::where('provider_invoice_id', 'in_1')->value('status'));
        Notification::assertSentTo($user, AccountNotice::class, fn ($n) => $n->kind === 'payment_failed');

        // Deletion: the event body is ignored; state is re-fetched from the provider.
        $remote = 'canceled';
        [$p3, $h3] = $this->signed(['id' => 'evt_3', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_123', 'status' => 'active']]]);
        $this->call('POST', '/api/v1/webhooks/billing', [], [], [], $this->server($h3), $p3)->assertOk();
        $this->assertSame('canceled', $subscription->fresh()->status);
        app(EntitlementService::class)->forget();
        $this->assertSame('free', app(EntitlementService::class)->resolve($user)['plan']->slug);
        Notification::assertSentTo($user, AccountNotice::class, fn ($n) => $n->kind === 'subscription_ended');
    }

    public function test_manual_grants_expire_on_reconcile(): void
    {
        $user = $this->makeUser();
        $subscription = $this->subscribe($user, 'pro_monthly');
        $subscription->update(['current_period_end' => now()->subMinute()]);

        $this->artisan('lf:billing:reconcile')->assertSuccessful();

        $this->assertSame('canceled', $subscription->fresh()->status);
    }

    /** @param array<string, string> $headers */
    private function server(array $headers): array
    {
        return collect($headers)->mapWithKeys(fn ($v, $k) => [$k === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all();
    }
}
