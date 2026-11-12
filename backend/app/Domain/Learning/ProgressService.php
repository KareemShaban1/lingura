<?php

namespace App\Domain\Learning;

use App\Domain\Adaptive\AdaptiveEngine;
use App\Domain\Vocabulary\VocabularyService;
use App\Models\AiConversation;
use App\Models\AssessmentAttempt;
use App\Models\ExerciseAttempt;
use App\Models\GrammarMastery;
use App\Models\LearnerMistakePattern;
use App\Models\LearnerProfile;
use App\Models\LearningPlanItem;
use App\Models\LearningSession;
use App\Models\PronunciationResult;
use App\Models\UserProgress;
use App\Models\UserVocabulary;
use App\Models\WritingSubmission;
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

    /**
     * Practice accuracy per skill over the last 30 days, from recorded answers only.
     *
     * @return array<string, array{answered:int, correct:int, accuracy:?float}>
     */
    public function skillAccuracy(LearnerProfile $profile): array
    {
        return UserProgress::query()
            ->join('skills', 'skills.id', '=', 'user_progress.skill_id')
            ->where('learner_profile_id', $profile->id)
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->groupBy('skills.slug')
            ->selectRaw('skills.slug, sum(questions_answered) as answered, sum(questions_correct) as correct')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->slug => [
                'answered' => (int) $r->answered,
                'correct' => (int) $r->correct,
                'accuracy' => $r->answered > 0 ? round($r->correct / $r->answered, 3) : null,
            ]])->all();
    }

    /** @return list<array<string, int|string>> one row per day with questions answered per skill */
    public function dailySkillActivity(LearnerProfile $profile, int $days): array
    {
        $tz = $this->timezone($profile);
        $rows = UserProgress::query()->join('skills', 'skills.id', '=', 'user_progress.skill_id')
            ->where('learner_profile_id', $profile->id)
            ->where('date', '>=', Carbon::now($tz)->subDays($days - 1)->toDateString())
            ->get(['date', 'skills.slug', 'questions_answered', 'words_reviewed']);

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now($tz)->subDays($i)->toDateString();
            $series[$date] = ['date' => $date];
        }
        foreach ($rows as $r) {
            $date = $r->date->toDateString();
            if (isset($series[$date])) {
                $series[$date][$r->slug] = ($series[$date][$r->slug] ?? 0) + $r->questions_answered + $r->words_reviewed;
            }
        }

        return array_values($series);
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
            'vocabulary_due' => UserVocabulary::where('learner_profile_id', $profile->id)->where('due_at', '<=', now())->count(),
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
            'skill_accuracy' => $this->skillAccuracy($profile),
            'daily_skill_activity' => $this->dailySkillActivity($profile, 30),
            'vocabulary' => app(VocabularyService::class)->summary($profile),
            'grammar' => [
                'topics_practised' => GrammarMastery::where('learner_profile_id', $profile->id)->count(),
                'topics_mastered' => GrammarMastery::where('learner_profile_id', $profile->id)->where('answers_total', '>=', 3)->where('mastery', '>=', 0.8)->count(),
            ],
            'reading' => [
                'recent_wpm' => ExerciseAttempt::where('learner_profile_id', $profile->id)->whereNotNull('words_per_minute')
                    ->latest('completed_at')->limit(5)->pluck('words_per_minute')->reverse()->values(),
            ],
            'exercises_completed' => ExerciseAttempt::where('learner_profile_id', $profile->id)->where('status', 'completed')->count(),
            'level_progress' => app(AdaptiveEngine::class)->levelProgress($profile),
            'writing' => WritingSubmission::where('writing_submissions.learner_profile_id', $profile->id)
                ->whereNull('assessment_attempt_id')->where('status', 'evaluated')
                ->join('writing_evaluations', 'writing_evaluations.writing_submission_id', '=', 'writing_submissions.id')
                ->orderByDesc('writing_submissions.created_at')->limit(10)
                ->get(['writing_submissions.created_at', 'writing_evaluations.overall_score', 'writing_submissions.word_count'])
                ->reverse()->values()->map(fn ($r) => ['date' => $r->created_at->toDateString(), 'score' => (float) $r->overall_score, 'words' => $r->word_count]),
            'speaking' => [
                'conversations' => AiConversation::where('learner_profile_id', $profile->id)->where('learner_turns', '>', 0)->count(),
                'learner_turns' => (int) AiConversation::where('learner_profile_id', $profile->id)->sum('learner_turns'),
                'conversation_minutes' => round(LearningSession::where('learner_profile_id', $profile->id)->where('activity_type', 'conversation')->sum('active_seconds') / 60, 1),
                'speaking_minutes' => round(AiConversation::where('learner_profile_id', $profile->id)->sum('speaking_seconds') / 60, 1),
                'pronunciation_accuracy' => ($p = PronunciationResult::query()
                    ->join('audio_recordings', 'audio_recordings.id', '=', 'pronunciation_results.audio_recording_id')
                    ->where('audio_recordings.learner_profile_id', $profile->id)
                    ->where('pronunciation_results.created_at', '>=', now()->subDays(30))->avg('accuracy')) !== null ? round((float) $p, 3) : null,
            ],
            'mistake_patterns' => LearnerMistakePattern::where('learner_profile_id', $profile->id)->orderByDesc('occurrences')->limit(6)->get()
                ->map(fn ($m) => ['category' => $m->category, 'topic' => $m->topic_slug ?: null, 'occurrences' => $m->occurrences, 'sources' => $m->sources, 'example' => $m->example]),
            'attempts' => $profile->assessmentAttempts()->where('status', AssessmentAttempt::STATUS_COMPLETED)
                ->with(['overallLevel', 'sections.skill', 'sections.estimatedLevel'])->latest('completed_at')->get(),
        ];
    }
}
