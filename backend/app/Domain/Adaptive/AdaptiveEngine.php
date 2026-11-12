<?php

namespace App\Domain\Adaptive;

use App\Domain\Assessment\Engine\CefrScale;
use App\Domain\Learning\PlanGenerator;
use App\Models\AiFeedback;
use App\Models\CefrLevel;
use App\Models\ExerciseAnswer;
use App\Models\GrammarMastery;
use App\Models\LearnerMistakePattern;
use App\Models\LearnerProfile;
use App\Models\LearningPlan;
use App\Models\Skill;
use App\Models\UserProgress;
use Illuminate\Support\Facades\DB;

/**
 * Adaptive engine v1 (rules_v1, documented in docs/12-tutor-and-adaptive.md). Every signal is computed from
 * stored evidence with fixed, explainable thresholds:
 *  - mistake patterns: repeated errors per category/topic across tutor, writing and exercises (30 days);
 *  - difficulty offsets: practice accuracy per skill over 14 days raises or lowers the content level;
 *  - revision: grammar topics not practised for a week whose mastery is below 0.8;
 *  - readiness: ≥ 80 % accuracy on next-level practice suggests a progress check.
 * When the signals change, a new plan version is generated (trigger "adaptive", max once a day).
 */
class AdaptiveEngine
{
    public function __construct(private readonly PlanGenerator $generator) {}

    public function refresh(LearnerProfile $profile): void
    {
        $this->detectPatterns($profile);
        $plan = LearningPlan::where('learner_profile_id', $profile->id)->where('status', LearningPlan::STATUS_ACTIVE)->with('currentVersion')->first();
        if (! $plan?->currentVersion) {
            return;
        }
        $previous = $plan->currentVersion->inputs_snapshot['adaptive'] ?? null;
        $current = $this->planSignals($profile);
        $cooldown = now()->subHours(config('learning.adaptive.regenerate_cooldown_hours'));
        $recentlyAdapted = $plan->versions()->where('trigger', 'adaptive')->where('created_at', '>', $cooldown)->exists();

        if ($previous !== $current && ! $recentlyAdapted) {
            $this->generator->generate($profile, 'adaptive');
        }
    }

    /**
     * The subset of signals that shapes plans (stored in the plan version snapshot for auditability).
     *
     * @return array{offsets: array<string,int>, recurring: list<string>, revision: list<string>}
     */
    public function planSignals(LearnerProfile $profile): array
    {
        return [
            'offsets' => $this->difficultyOffsets($profile),
            'recurring' => $this->recurringTopics($profile),
            'revision' => $this->revisionTopics($profile),
        ];
    }

    public function detectPatterns(LearnerProfile $profile): void
    {
        $since = now()->subDays(config('learning.adaptive.pattern_window_days'));
        $groups = [];

        foreach (AiFeedback::where('learner_profile_id', $profile->id)->where('created_at', '>=', $since)->orderBy('created_at')->get() as $f) {
            $key = $f->type.'|'.($f->topic_slug ?? '');
            $groups[$key] ??= ['category' => $f->type, 'topic' => $f->topic_slug ?? '', 'count' => 0, 'sources' => [], 'example' => null, 'first' => $f->created_at, 'last' => $f->created_at];
            $groups[$key]['count']++;
            $groups[$key]['sources'][$f->source] = true;
            $groups[$key]['example'] = "{$f->original} → {$f->correction}";
            $groups[$key]['last'] = $f->created_at;
        }

        $wrong = ExerciseAnswer::query()
            ->join('exercise_attempts', 'exercise_attempts.id', '=', 'exercise_answers.exercise_attempt_id')
            ->join('exercises', 'exercises.id', '=', 'exercise_attempts.exercise_id')
            ->join('grammar_topics', 'grammar_topics.id', '=', 'exercises.grammar_topic_id')
            ->where('exercise_attempts.learner_profile_id', $profile->id)
            ->where('exercise_answers.is_correct', false)
            ->where('exercise_answers.answered_at', '>=', $since)
            ->groupBy('grammar_topics.slug')
            ->selectRaw('grammar_topics.slug, count(*) as n, min(exercise_answers.answered_at) as first, max(exercise_answers.answered_at) as last')
            ->get();
        foreach ($wrong as $w) {
            $key = 'grammar|'.$w->slug;
            $groups[$key] ??= ['category' => 'grammar', 'topic' => $w->slug, 'count' => 0, 'sources' => [], 'example' => null, 'first' => $w->first, 'last' => $w->last];
            $groups[$key]['count'] += (int) $w->n;
            $groups[$key]['sources']['exercises'] = true;
            $groups[$key]['last'] = max((string) $groups[$key]['last'], (string) $w->last);
        }

        DB::transaction(function () use ($profile, $groups) {
            $keep = [];
            foreach ($groups as $g) {
                $row = LearnerMistakePattern::updateOrCreate(
                    ['learner_profile_id' => $profile->id, 'category' => $g['category'], 'topic_slug' => $g['topic']],
                    ['occurrences' => $g['count'], 'sources' => count($g['sources']), 'example' => $g['example'], 'first_seen_at' => $g['first'], 'last_seen_at' => $g['last']],
                );
                $keep[] = $row->id;
            }
            LearnerMistakePattern::where('learner_profile_id', $profile->id)->whereNotIn('id', $keep)->delete();
        });
    }

    /** @return array<string, int> skill slug => -1 | 0 | +1 (only non-zero entries) */
    public function difficultyOffsets(LearnerProfile $profile): array
    {
        $c = config('learning.adaptive');
        $rows = UserProgress::query()->join('skills', 'skills.id', '=', 'user_progress.skill_id')
            ->where('learner_profile_id', $profile->id)->where('date', '>=', now()->subDays($c['window_days'])->toDateString())
            ->groupBy('skills.slug')->selectRaw('skills.slug, sum(questions_answered) as answered, sum(questions_correct) as correct')->get();

        $offsets = [];
        foreach ($rows as $r) {
            if ($r->answered < $c['min_answers']) {
                continue;
            }
            $accuracy = $r->correct / $r->answered;
            $offset = $accuracy >= $c['raise_at'] ? 1 : ($accuracy <= $c['lower_at'] ? -1 : 0);
            if ($offset !== 0) {
                $offsets[$r->slug] = $offset;
            }
        }
        ksort($offsets);

        return $offsets;
    }

    /** @return list<string> grammar topic slugs with repeated mistakes, most frequent first */
    public function recurringTopics(LearnerProfile $profile): array
    {
        return LearnerMistakePattern::where('learner_profile_id', $profile->id)->where('topic_slug', '!=', '')
            ->where('occurrences', '>=', config('learning.adaptive.recurring_threshold'))
            ->orderByDesc('occurrences')->orderBy('topic_slug')->pluck('topic_slug')->unique()->values()->all();
    }

    /** @return list<string> topics due for revision: not practised for 7 days and not yet mastered */
    public function revisionTopics(LearnerProfile $profile): array
    {
        return GrammarMastery::where('learner_profile_id', $profile->id)->where('answers_total', '>=', 3)
            ->where('mastery', '<', 0.8)->where('last_practiced_at', '<', now()->subDays(7))
            ->with('topic')->orderBy('mastery')->get()->pluck('topic.slug')->values()->all();
    }

    /**
     * Progress toward the next level per assessed skill, plus readiness for a progress check.
     *
     * @return array<string, array{level: string, next: ?string, progress: ?float, ready_for_check: bool, next_level_accuracy: ?float, next_level_answers: int}>
     */
    public function levelProgress(LearnerProfile $profile): array
    {
        $scale = CefrScale::load();
        $levels = CefrLevel::orderBy('ordinal')->get()->values();
        $c = config('learning.adaptive');
        $out = [];

        foreach ($profile->skillProfiles()->with(['skill', 'level'])->where('status', 'assessed')->get() as $sp) {
            $ordinal = $sp->level->ordinal;
            $next = $levels->firstWhere('ordinal', $ordinal + 1);
            $progress = null;
            if ($next && $sp->theta !== null) {
                $lower = $ordinal === 0 ? $sp->level->anchor_theta - 1 : ($levels[$ordinal - 1]->anchor_theta + $sp->level->anchor_theta) / 2;
                $upper = ($sp->level->anchor_theta + $next->anchor_theta) / 2;
                $progress = round(max(0, min(1, ($sp->theta - $lower) / ($upper - $lower))), 2);
            }

            $answers = $next ? ExerciseAnswer::query()
                ->join('exercise_attempts', 'exercise_attempts.id', '=', 'exercise_answers.exercise_attempt_id')
                ->join('exercises', 'exercises.id', '=', 'exercise_attempts.exercise_id')
                ->where('exercise_attempts.learner_profile_id', $profile->id)
                ->where('exercises.skill_id', $sp->skill_id)
                ->where('exercises.cefr_level_id', $next->id)
                ->where('exercise_answers.answered_at', '>=', now()->subDays(30))
                ->selectRaw('count(*) as n, sum(case when exercise_answers.is_correct then 1 else 0 end) as correct')->first() : null;
            $n = (int) ($answers->n ?? 0);
            $accuracy = $n ? round($answers->correct / $n, 3) : null;

            $out[$sp->skill->slug] = [
                'level' => $sp->level->label,
                'next' => $next?->label,
                'progress' => $progress,
                'next_level_answers' => $n,
                'next_level_accuracy' => $accuracy,
                'ready_for_check' => $n >= $c['readiness_min_answers'] && $accuracy >= $c['readiness_accuracy'],
            ];
        }

        return $out;
    }
}
