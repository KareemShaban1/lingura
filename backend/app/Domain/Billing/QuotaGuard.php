<?php

namespace App\Domain\Billing;

use App\Domain\Shared\DomainError;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Server-side quota enforcement. Counters live in `usage_counters` keyed by (user, feature, period start);
 * a single conditional upsert makes consumption atomic under concurrency — a request either gets its
 * units or a 429 `quota_exceeded`, never a partial or double spend.
 */
class QuotaGuard
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function consume(User $user, string $feature, int $quantity = 1): void
    {
        $limit = $this->entitlements->limit($user, $feature);
        $period = $this->periodStart($user, $feature);

        if ($limit === null) { // unlimited: still metered for analytics
            DB::statement('insert into usage_counters (user_id, feature, period_start, used, updated_at) values (?, ?, ?, ?, now())
                on conflict (user_id, feature, period_start) do update set used = usage_counters.used + excluded.used, updated_at = now()',
                [$user->id, $feature, $period->toDateString(), $quantity]);

            return;
        }

        $granted = $quantity <= $limit && DB::selectOne('insert into usage_counters (user_id, feature, period_start, used, updated_at) values (?, ?, ?, ?, now())
            on conflict (user_id, feature, period_start) do update set used = usage_counters.used + excluded.used, updated_at = now()
            where usage_counters.used + excluded.used <= ? returning used',
            [$user->id, $feature, $period->toDateString(), $quantity, $limit]) !== null;

        if (! $granted) {
            throw $this->exceeded($user, $feature, $limit);
        }
    }

    /** Returns units after a failed operation (never below zero). */
    public function release(User $user, string $feature, int $quantity = 1): void
    {
        DB::update('update usage_counters set used = greatest(used - ?, 0), updated_at = now() where user_id = ? and feature = ? and period_start = ?',
            [$quantity, $user->id, $feature, $this->periodStart($user, $feature)->toDateString()]);
    }

    /**
     * Consume, run, and refund if the operation throws — learners are not charged for our failures.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function using(User $user, string $feature, callable $operation, int $quantity = 1): mixed
    {
        $this->consume($user, $feature, $quantity);
        try {
            return $operation();
        } catch (Throwable $e) {
            $this->release($user, $feature, $quantity);
            throw $e;
        }
    }

    /** Standing limits (e.g. number of target languages) checked against a current count. */
    public function assertBelow(User $user, string $feature, int $current): void
    {
        $limit = $this->entitlements->limit($user, $feature);
        if ($limit !== null && $current >= $limit) {
            throw $this->exceeded($user, $feature, $limit);
        }
    }

    public function remaining(User $user, string $feature): ?int
    {
        $limit = $this->entitlements->limit($user, $feature);

        return $limit === null ? null : max(0, $limit - $this->used($user, $feature));
    }

    public function used(User $user, string $feature): int
    {
        return (int) DB::table('usage_counters')->where('user_id', $user->id)->where('feature', $feature)
            ->where('period_start', $this->periodStart($user, $feature)->toDateString())->value('used');
    }

    /** @return list<array{feature: string, period: string, limit: ?int, used: int, resets_at: ?string}> */
    public function summary(User $user, ?callable $standingCount = null): array
    {
        $rows = [];
        foreach (config('billing.features') as $feature => $period) {
            $rows[] = [
                'feature' => $feature,
                'period' => $period,
                'limit' => $this->entitlements->limit($user, $feature),
                'used' => $period === 'total' && $standingCount ? (int) $standingCount($feature) : $this->used($user, $feature),
                'resets_at' => $this->resetsAt($user, $feature)?->toIso8601String(),
            ];
        }

        return $rows;
    }

    public function periodStart(User $user, string $feature): Carbon
    {
        $now = Carbon::now($this->timezone($user));

        return match (config("billing.features.{$feature}", 'day')) {
            'month' => $now->startOfMonth(),
            'total' => Carbon::create(1970, 1, 1),
            default => $now->startOfDay(),
        };
    }

    public function resetsAt(User $user, string $feature): ?Carbon
    {
        $start = $this->periodStart($user, $feature);

        return match (config("billing.features.{$feature}", 'day')) {
            'month' => $start->copy()->addMonth(),
            'total' => null,
            default => $start->copy()->addDay(),
        };
    }

    private function exceeded(User $user, string $feature, int $limit): DomainError
    {
        $resets = $this->resetsAt($user, $feature);
        $message = match (true) {
            $limit === 0 => 'This feature is not included in your plan.',
            $resets === null => 'You have reached the limit of your plan.',
            default => 'You have reached your plan’s limit for now. It resets on '.$resets->toFormattedDayDateString().'.',
        };

        return new DomainError($message, 'quota_exceeded', 429, [
            'feature' => $feature, 'limit' => $limit, 'resets_at' => $resets?->toIso8601String(),
            'plan' => $this->entitlements->resolve($user)['plan']->slug,
        ]);
    }

    private function timezone(User $user): string
    {
        return $user->profile?->timezone ?: 'UTC';
    }
}
