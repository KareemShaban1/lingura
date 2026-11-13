<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Billing\BillingService;
use App\Domain\Shared\AuditLogger;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Controller;
use App\Models\BillingEvent;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\Sql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function subscriptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $rows = Subscription::query()
            ->when($request->query('status'), fn ($query, $s) => $query->where('status', $s))
            ->when($request->query('provider'), fn ($query, $p) => $query->where('provider', $p))
            ->when($q !== '', fn ($query) => $query->whereHas('user', fn ($u) => $u->where('email', Sql::ilike(), "%{$q}%")))
            ->with(['plan', 'user'])->latest('id')->paginate(25);

        return response()->json([
            'data' => collect($rows->items())->map(fn ($s) => UserAdminController::subscription($s)),
            'meta' => ['page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    public function revoke(Request $request, Subscription $subscription, BillingService $billing): JsonResponse
    {
        $billing->endNow($subscription->load('plan'), $request->user());

        return response()->json(['data' => UserAdminController::subscription($subscription->fresh(['plan', 'user']))]);
    }

    public function plans(): JsonResponse
    {
        return response()->json(['data' => SubscriptionPlan::with('entitlements')->orderBy('rank')->orderBy('price_minor')->get()->map(fn ($p) => [
            ...BillingController::plan($p),
            'id' => $p->id, 'is_active' => $p->is_active, 'is_public' => $p->is_public, 'provider_price_id' => $p->provider_price_id,
            'subscribers' => Subscription::where('subscription_plan_id', $p->id)->whereIn('status', Subscription::LIVE_STATUSES)->count(),
        ]), 'meta' => ['features' => config('billing.features')]]);
    }

    public function updatePlan(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'price_minor' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'provider_price_id' => ['sometimes', 'nullable', 'string', 'max:120', Rule::unique('subscription_plans')->ignore($plan->id)],
            'limits' => ['sometimes', 'array'],
            'limits.*' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $old = $plan->only(['is_active', 'is_public', 'price_minor', 'provider_price_id']) + ['limits' => $plan->entitlements()->pluck('limit', 'feature')->all()];
        $plan->update(collect($data)->except('limits')->all());
        foreach ($data['limits'] ?? [] as $feature => $limit) {
            if (array_key_exists($feature, config('billing.features'))) {
                PlanEntitlement::updateOrCreate(['subscription_plan_id' => $plan->id, 'feature' => $feature], ['limit' => $limit, 'period' => config("billing.features.{$feature}")]);
            }
        }
        $this->audit->log('admin.plan_updated', $plan, $old, $data);

        return $this->plans();
    }

    public function events(Request $request): JsonResponse
    {
        $rows = BillingEvent::query()->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($e) => [
            'id' => $e->id, 'provider' => $e->provider, 'event_id' => $e->event_id, 'type' => $e->type, 'status' => $e->status,
            'error' => $e->error, 'created_at' => $e->created_at->toIso8601String(), 'processed_at' => $e->processed_at?->toIso8601String(),
        ])]);
    }
}
