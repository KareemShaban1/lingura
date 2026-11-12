<?php

namespace Database\Seeders;

use App\Models\PlanEntitlement;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/** Seeds plans and limits from config/billing.php. Idempotent; admins edit limits afterwards. */
class BillingPlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('billing.plans') as $slug => $def) {
            $priceId = isset($def['price_env']) ? env($def['price_env']) : null;
            $plan = SubscriptionPlan::firstOrNew(['slug' => $slug]);
            $plan->fill([
                'tier' => $def['tier'], 'interval' => $def['interval'], 'price_minor' => $def['price_minor'],
                'rank' => $def['rank'], 'seats' => $def['seats'], 'currency' => 'USD', 'is_active' => true, 'is_public' => true,
                // Never overwrite a price id an admin configured with an empty env value.
                'provider_price_id' => $priceId ?: $plan->provider_price_id,
            ])->save();

            $limits = $def['limits'] ?? config("billing.tier_limits.{$def['tier']}");
            foreach (config('billing.features') as $feature => $period) {
                PlanEntitlement::firstOrCreate(
                    ['subscription_plan_id' => $plan->id, 'feature' => $feature],
                    ['limit' => array_key_exists($feature, $limits) ? $limits[$feature] : 0, 'period' => $period], // null = unlimited
                );
            }
        }
    }
}
