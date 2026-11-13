<?php

namespace App\Domain\Billing;

use App\Domain\Shared\DomainError;
use App\Models\User;
use App\Support\Sql;
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
            $this->consumeLocked($user, $feature, $period->toDateString(), $quantity, null);

            return;
        }

        $granted = $quantity <= $limit && (Sql::isPgsql()
            ? $this->consumePgsql($user, $feature, $period->toDateString(), $quantity, $limit)
            : $this->consumeLocked($user, $feature, $period->toDateString(), $quantity, $limit));
        if (! $granted) {
            throw $this->exceeded($user, $feature, $limit);
        }
    }

    /** PostgreSQL: one conditional upsert — granted only if the row was written. */
    private function consumePgsql(User $user, string $feature, string $period, int $quantity, int $limit): bool
    {
        return DB::selectOne('insert into usage_counters (user_id, feature, period_start, used, updated_at) values (?, ?, ?, ?, now())
            on conflict (user_id, feature, period_start) do update set used = usage_counters.used + excluded.used, updated_at = now()
            where usage_counters.used + excluded.used <= ? returning used',
            [$user->id, $feature, $period, $quantity, $limit]) !== null;
    }

    /**
     * Portable path (MySQL/MariaDB, and unlimited features): make sure the row exists, then lock it,
     * check and add — concurrent requests serialise on the row lock, so the limit still holds.
     */
    private function consumeLocked(User $user, string $feature, string $period, int $quantity, ?int $limit): bool
    {
        return DB::transaction(function () use ($user, $feature, $period, $quantity, $limit) {
            DB::table('usage_counters')->insertOrIgnore(['user_id' => $user->id, 'feature' => $feature, 'period_start' => $period, 'used' => 0, 'updated_at' => now()]);
            $row = DB::table('usage_counters')->where('user_id', $user->id)->where('feature', $feature)->where('period_start', $period)->lockForUpdate()->first();
            if ($limit !== null && $row->used + $quantity > $limit) {
                return false;
            }
            DB::table('usage_counters')->where('id', $row->id)->update(['used' => $row->used + $quantity, 'updated_at' => now()]);

            return true;
        });
    }

    /** Returns units after a failed operation (never below zero). */
    public function release(User $user, string $feature, int $quantity = 1): void
    {
        // CASE rather than greatest(used - ?, 0): `used` is unsigned, and MySQL rejects a negative intermediate.
        DB::update('update usage_counters set used = case when used > ? then used - ? else 0 end, updated_at = CURRENT_TIMESTAMP where user_id = ? and feature = ? and period_start = ?',
            [$quantity, $quantity, $user->id, $feature, $this->periodStart($user, $feature)->toDateString()]);
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
