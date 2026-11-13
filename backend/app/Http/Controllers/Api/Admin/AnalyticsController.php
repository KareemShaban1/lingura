<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Sql;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Platform analytics from recorded events only (no sampling, no estimates presented as facts). */
class AnalyticsController extends Controller
{
    public function overview(): JsonResponse
    {
        $now = now();
        $active = fn (int $days) => DB::table('learning_events')->where('occurred_at', '>=', $now->copy()->subDays($days))->distinct()->count('user_id');

        $live = Subscription::whereIn('status', Subscription::LIVE_STATUSES)->with('plan')->get();
        $paid = $live->where('provider', '!=', 'manual');
        $mrr = $paid->sum(fn (Subscription $s) => $s->plan->interval === 'year' ? $s->plan->price_minor / 12 : $s->plan->price_minor);

        $aiDaily = DB::table('ai_usage_records')->where('created_at', '>=', $now->copy()->subDays(30))
            ->selectRaw(Sql::day('created_at').' as day, sum(cost_micros) as cost, count(*) as calls')
            ->groupByRaw(Sql::day('created_at'))->orderBy('day')->get();
        $aiByOperation = DB::table('ai_usage_records')->where('created_at', '>=', $now->copy()->subDays(30))
            ->selectRaw('operation, sum(cost_micros) as cost, count(*) as calls, sum(case when status = \'error\' then 1 else 0 end) as errors')
            ->groupBy('operation')->orderByDesc('cost')->get();

        return response()->json(['data' => [
            'users' => [
                'total' => User::count(),
                'new_7d' => User::where('created_at', '>=', $now->copy()->subDays(7))->count(),
                'new_30d' => User::where('created_at', '>=', $now->copy()->subDays(30))->count(),
                'dau' => $active(1), 'wau' => $active(7), 'mau' => $active(30),
            ],
            'subscriptions' => [
                'active_paid' => $paid->count(),
                'complimentary' => $live->where('provider', 'manual')->count(),
                'by_tier' => $paid->groupBy(fn ($s) => $s->plan->tier)->map->count(),
                'mrr_minor' => (int) round($mrr),
                'currency' => 'USD',
                'cancel_scheduled' => $paid->where('cancel_at_period_end', true)->count(),
                'past_due' => $paid->where('status', 'past_due')->count(),
            ],
            'engagement_7d' => [
                'minutes' => (int) round((float) DB::table('learning_events')->where('event_type', 'session.completed')->where('occurred_at', '>=', $now->copy()->subDays(7))->sum('value')),
                'exercises' => DB::table('learning_events')->where('event_type', 'exercise.completed')->where('occurred_at', '>=', $now->copy()->subDays(7))->count(),
                'conversations' => DB::table('learning_events')->where('event_type', 'tutor.completed')->where('occurred_at', '>=', $now->copy()->subDays(7))->count(),
                'writings' => DB::table('learning_events')->where('event_type', 'writing.evaluated')->where('occurred_at', '>=', $now->copy()->subDays(7))->count(),
                'reviews' => DB::table('learning_events')->where('event_type', 'vocabulary.reviewed')->where('occurred_at', '>=', $now->copy()->subDays(7))->count(),
            ],
            'ai_cost_30d' => [
                'total_micros' => (int) $aiByOperation->sum('cost'),
                'by_operation' => $aiByOperation->map(fn ($r) => ['operation' => $r->operation, 'cost_micros' => (int) $r->cost, 'calls' => (int) $r->calls, 'errors' => (int) $r->errors]),
                'daily' => $aiDaily->map(fn ($r) => ['date' => $r->day, 'cost_micros' => (int) $r->cost, 'calls' => (int) $r->calls]),
            ],
            'retention' => $this->cohorts(8),
            // Format bandit (30 days): how often each format was chosen and its mean reward once resolved.
            'personalization' => DB::table('personalization_decisions')->where('created_at', '>=', $now->copy()->subDays(30))
                ->groupBy('context', 'chosen_arm')->orderBy('context')->orderBy('chosen_arm')
                ->selectRaw('context, chosen_arm, count(*) as decisions, count(reward) as resolved, avg(reward) as mean_reward')
                ->get()->map(fn ($r) => ['context' => $r->context, 'arm' => $r->chosen_arm, 'decisions' => (int) $r->decisions,
                    'resolved' => (int) $r->resolved, 'mean_reward' => $r->mean_reward === null ? null : round((float) $r->mean_reward, 3)]),
        ]]);
    }

    /**
     * Weekly signup cohorts: share of each cohort with at least one learning event in week N after signup.
     *
     * @return list<array{cohort: string, size: int, weeks: list<?float>}>
     */
    private function cohorts(int $count): array
    {
        $start = Carbon::now()->startOfWeek(Carbon::MONDAY)->subWeeks($count - 1);
        $users = User::where('created_at', '>=', $start)->get(['id', 'created_at'])
            ->groupBy(fn ($u) => $u->created_at->copy()->startOfWeek(Carbon::MONDAY)->toDateString());
        $activity = DB::table('learning_events')->where('occurred_at', '>=', $start)
            ->selectRaw('user_id, '.Sql::weekStart('occurred_at').' as week')
            ->distinct()->get()->groupBy('user_id')->map(fn ($rows) => $rows->pluck('week')->flip());

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $week = $start->copy()->addWeeks($i);
            $members = $users->get($week->toDateString(), collect());
            $weeks = [];
            for ($n = 0; $n <= min(4, $count - 1 - $i); $n++) {
                $target = $week->copy()->addWeeks($n)->toDateString();
                $weeks[] = $members->isEmpty() ? null
                    : round($members->filter(fn ($u) => isset($activity[$u->id][$target]))->count() / $members->count(), 3);
            }
            $out[] = ['cohort' => $week->toDateString(), 'size' => $members->count(), 'weeks' => $weeks];
        }

        return $out;
    }
}
