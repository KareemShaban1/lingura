<?php

namespace App\Domain\Billing;

use App\Models\OrganizationMember;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;

/**
 * Resolves which plan grants a user's entitlements. Sources: the user's own live subscription and any
 * live seat subscription of an organization they belong to; the highest-ranked plan wins, else Free.
 */
class EntitlementService
{
    /** @var array<int, array{plan: SubscriptionPlan, subscription: ?Subscription, source: string}> */
    private array $memo = [];

    /** @return array{plan: SubscriptionPlan, subscription: ?Subscription, source: string} source: personal | organization | free */
    public function resolve(User $user): array
    {
        if (isset($this->memo[$user->id])) {
            return $this->memo[$user->id];
        }
        $orgIds = OrganizationMember::where('user_id', $user->id)->pluck('organization_id');
        $candidates = Subscription::query()
            ->whereIn('status', Subscription::LIVE_STATUSES)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('user_id', $user->id)->whereNull('organization_id'))
                ->orWhereIn('organization_id', $orgIds))
            ->with('plan.entitlements')
            ->get()
            ->sortByDesc(fn (Subscription $s) => $s->plan->rank);

        $best = $candidates->first();

        return $this->memo[$user->id] = $best
            ? ['plan' => $best->plan, 'subscription' => $best, 'source' => $best->organization_id ? 'organization' : 'personal']
            : ['plan' => SubscriptionPlan::free()->load('entitlements'), 'subscription' => null, 'source' => 'free'];
    }

    /** null = unlimited; 0 = not included. Unknown features are not included. */
    public function limit(User $user, string $feature): ?int
    {
        $row = $this->resolve($user)['plan']->entitlements->firstWhere('feature', $feature);

        return $row ? ($row->limit === null ? null : (int) $row->limit) : 0;
    }

    public function forget(?User $user = null): void
    {
        if ($user) {
            unset($this->memo[$user->id]);
        } else {
            $this->memo = [];
        }
    }
}
