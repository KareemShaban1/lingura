<?php

namespace App\Domain\Learning;

use App\Models\AssessmentAttempt;
use App\Models\LearnerProfile;
use App\Models\LearningPlanItem;
use App\Models\LearningSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read models for the dashboard and progress screens. All numbers are derived from stored evidence —
 * no synthetic or placeholder metrics.
 */
class ProgressService
{
    public function timezone(LearnerProfile $profile): string
    {
        return $profile->user->profile?->timezone ?? 'UTC';
    }

    /** @return array<string, int> local date (Y-m-d) => seconds studied */
    public function dailySeconds(LearnerProfile $profile, int $days): array
    {
        $tz = $this->timezone($profile);
        $since = Carbon::now($tz)->startOfDay()->subDays($days - 1)->utc();

        return LearningSession::query()
            ->where('learner_profile_id', $profile->id)
            ->whereNotNull('ended_at')
            ->where('ended_at', '>=', $since)
            ->selectRaw("to_char((ended_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD') as day, sum(active_seconds) as seconds", [$tz])
            ->groupBy('day')
            ->pluck('seconds', 'day')
            ->map(fn ($s) => (int) $s)
            ->all();
    }

    public function streak(LearnerProfile $profile): array
    {
        $daily = $this->dailySeconds($profile, 400);
        $today = Carbon::now($this->timezone($profile))->startOfDay();
        $cursor = isset($daily[$today->toDateString()]) ? $today->copy() : $today->copy()->subDay();
        $current = 0;
        while (isset($daily[$cursor->toDateString()])) {
            $current++;
            $cursor->subDay();
        }

        return ['current_days' => $current, 'studied_today' => isset($daily[$today->toDateString()])];
    }

    public function dashboard(LearnerProfile $profile): array
    {
        $tz = $this->timezone($profile);
        $today = Carbon::now($tz)->toDateString();
        $plan = $profile->plan()->with('currentVersion')->first();

        $todayItems = $plan?->currentVersion
            ? LearningPlanItem::where('learning_plan_version_id', $plan->current_version_id)
                ->whereDate('scheduled_date', $today)->with(['skill', 'targetLevel', 'version.plan.learnerProfile.targetLanguage'])->orderBy('position')->get()
            : collect();

        $week = $this->dailySeconds($profile, 7);
        $weekSeries = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now($tz)->subDays($i)->toDateString();
            $weekSeries[] = ['date' => $date, 'minutes' => round(($week[$date] ?? 0) / 60, 1)];
        }

        $latestAttempt = $profile->assessmentAttempts()->latest('started_at')->with('overallLevel')->first();

        return [
            'today' => $today,
            'plan_status' => $plan?->status,
            'today_items' => $todayItems,
            'today_minutes_planned' => $todayItems->sum('estimated_minutes'),
            'today_minutes_done' => round(($week[$today] ?? 0) / 60, 1),
            'week' => $weekSeries,
            'week_minutes' => round(array_sum($week) / 60, 1),
            'streak' => $this->streak($profile),
            'skills' => $profile->skillProfiles()->with(['skill', 'level'])->get(),
            'latest_attempt' => $latestAttempt,
        ];
    }

    public function progress(LearnerProfile $profile): array
    {
        $tz = $this->timezone($profile);
        $daily = $this->dailySeconds($profile, 84);
        $weeks = [];
        for ($w = 11; $w >= 0; $w--) {
            $start = Carbon::now($tz)->startOfWeek()->subWeeks($w);
            $seconds = 0;
            $activeDays = 0;
            for ($d = 0; $d < 7; $d++) {
                $key = $start->copy()->addDays($d)->toDateString();
                $seconds += $daily[$key] ?? 0;
                $activeDays += isset($daily[$key]) ? 1 : 0;
            }
            $weeks[] = ['week_start' => $start->toDateString(), 'minutes' => round($seconds / 60, 1), 'active_days' => $activeDays];
        }

        $completedBySkill = DB::table('learning_sessions')
            ->join('learning_plan_items', 'learning_plan_items.id', '=', 'learning_sessions.learning_plan_item_id')
            ->join('skills', 'skills.id', '=', 'learning_plan_items.skill_id')
            ->where('learning_sessions.learner_profile_id', $profile->id)
            ->whereNotNull('learning_sessions.ended_at')
            ->groupBy('skills.slug')
            ->selectRaw('skills.slug, sum(learning_sessions.active_seconds) as seconds, count(*) as sessions')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->slug => ['minutes' => round($r->seconds / 60, 1), 'sessions' => (int) $r->sessions]]);

        return [
            'skills' => $profile->skillProfiles()->with(['skill', 'level', 'scores.level'])->get(),
            'weekly_activity' => $weeks,
            'practice_by_skill' => $completedBySkill,
            'streak' => $this->streak($profile),
            'total_minutes' => round(LearningSession::where('learner_profile_id', $profile->id)->sum('active_seconds') / 60, 1),
            'attempts' => $profile->assessmentAttempts()->where('status', AssessmentAttempt::STATUS_COMPLETED)
                ->with(['overallLevel', 'sections.skill', 'sections.estimatedLevel'])->latest('completed_at')->get(),
        ];
    }
}
