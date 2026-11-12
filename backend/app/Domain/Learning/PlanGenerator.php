<?php

namespace App\Domain\Learning;

use App\Domain\Assessment\Engine\CefrScale;
use App\Domain\Shared\LearningEventRecorder;
use App\Models\LearnerProfile;
use App\Models\LearningPlan;
use App\Models\LearningPlanVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rule-based plan generator (rules_v1). Deterministic: the same inputs always produce the same plan.
 * Every allocation and item carries a reason_code so learners can see *why* it is in their plan.
 */
class PlanGenerator
{
    public const ACTIVITY_BY_SKILL = [
        'grammar' => 'grammar_practice',
        'vocabulary' => 'vocabulary_building',
        'reading' => 'reading_practice',
        'listening' => 'listening_practice',
        'speaking' => 'speaking_practice',
        'writing' => 'writing_practice',
    ];

    public function __construct(private readonly LearningEventRecorder $events, private readonly PlanText $text) {}

    public function generate(LearnerProfile $profile, string $trigger): LearningPlanVersion
    {
        return DB::transaction(function () use ($profile, $trigger) {
            LearnerProfile::whereKey($profile->id)->lockForUpdate()->first();
            $profile->refresh();

            // One live (active or paused) plan per profile; a paused plan keeps its status when re-versioned.
            $plan = LearningPlan::where('learner_profile_id', $profile->id)
                ->where('status', '!=', LearningPlan::STATUS_ARCHIVED)
                ->first()
                ?? LearningPlan::create(['learner_profile_id' => $profile->id, 'status' => LearningPlan::STATUS_ACTIVE]);

            $inputs = PlanInputs::fromProfile($profile);
            $locale = $profile->user->profile?->interfaceLanguage?->code ?? 'en';
            $allocations = $this->allocate($inputs);
            $today = Carbon::now($inputs->timezone)->startOfDay();
            $horizon = (int) config('learning.plan.horizon_days', 7);

            $version = $plan->versions()->create([
                'version_number' => ((int) $plan->versions()->max('version_number')) + 1,
                'generator' => config('learning.plan.generator'),
                'trigger' => $trigger,
                'daily_minutes' => $inputs->dailyMinutes,
                'valid_from' => $today->toDateString(),
                'valid_until' => $today->copy()->addDays($horizon - 1)->toDateString(),
                'inputs_snapshot' => $inputs->toArray(),
                'rationale' => $this->text->rationale($inputs->hasCompletedAssessment, $inputs->overallOrdinal, $inputs->overallProvisional, $inputs->dailyMinutes, count($inputs->studyDays), $allocations, $locale),
                'created_at' => now(),
            ]);

            foreach ($allocations as $slug => $a) {
                $version->allocations()->create([
                    'skill_id' => $inputs->skills[$slug]['skill_id'],
                    'priority_score' => round($a['priority'], 3),
                    'minutes_per_week' => $a['minutes'],
                    'reason_code' => $a['reason'],
                ]);
            }

            foreach ($this->schedule($inputs, $allocations, $today, $horizon, $locale, $profile) as $item) {
                $version->items()->create($item);
            }

            $plan->update(['current_version_id' => $version->id]);
            $this->events->record($profile, 'plan.version_created', $version, metadata: ['trigger' => $trigger, 'version' => $version->version_number]);

            return $version->load(['allocations.skill', 'items']);
        });
    }

    /**
     * @return array<string, array{priority: float, minutes: int, reason: string}> ordered by priority desc
     */
    public function allocate(PlanInputs $inputs): array
    {
        $c = config('learning.plan');
        $priorities = [];
        foreach ($inputs->skills as $slug => $s) {
            $gap = ($s['assessed'] && $inputs->overallOrdinal !== null) ? max(0, $inputs->overallOrdinal - $s['ordinal']) : 0;
            $priority = $s['weight'] * (1 + $c['weakness_step'] * $gap);
            $priority *= $s['focus'] ? $c['focus_multiplier'] : 1;
            $priority *= $s['assessed'] ? 1 : $c['not_assessed_multiplier'];

            $reason = match (true) {
                $gap > 0 => 'weak_relative_to_profile',
                $s['focus'] => 'learner_focus',
                $s['assessed'] && $s['confidence'] === 'low' => 'low_confidence_estimate',
                ! $s['assessed'] => 'not_yet_assessed',
                $s['weight'] >= 1.2 => 'goal_priority',
                default => 'balanced_maintenance',
            };
            $priorities[$slug] = ['priority' => $priority, 'reason' => $reason];
        }

        uasort($priorities, fn ($a, $b) => $b['priority'] <=> $a['priority']);
        $weekly = $inputs->dailyMinutes * count($inputs->studyDays);
        $total = array_sum(array_column($priorities, 'priority'));

        foreach ($priorities as $slug => &$p) {
            $p['minutes'] = (int) (round($weekly * $p['priority'] / $total / 5) * 5);
        }

        return $priorities;
    }

    private function schedule(PlanInputs $inputs, array $allocations, Carbon $today, int $horizon, string $locale, LearnerProfile $profile): array
    {
        $scale = CefrScale::load();
        $remaining = array_map(fn ($a) => $a['minutes'], $allocations);
        $practised = [];
        $topics = $inputs->weakTopics;
        $items = [];
        $studyIndex = 0;
        $languageName = $profile->targetLanguage->name;

        for ($d = 0; $d < $horizon; $d++) {
            $date = $today->copy()->addDays($d);
            if (! in_array($date->dayOfWeek, $inputs->studyDays, true)) {
                continue;
            }
            $budget = $inputs->dailyMinutes;
            $position = 0;
            $usedToday = [];

            $push = function (array $item) use (&$items, &$position, $date) {
                $items[] = $item + ['scheduled_date' => $date->toDateString(), 'position' => $position++, 'public_id' => (string) Str::ulid()];
            };

            if ($studyIndex === 0 && ! $inputs->hasCompletedAssessment) {
                $minutes = min(20, $budget);
                $push([
                    'skill_id' => null, 'activity_type' => 'placement_assessment', 'estimated_minutes' => $minutes,
                    'reason_code' => 'assessment_required', 'target_level_id' => null,
                    'title' => $this->text->itemTitle('placement_assessment', null, null, null, $languageName, $locale),
                    'objective' => $this->text->itemObjective('placement_assessment', null, null, null, null, $languageName, $locale),
                ]);
                $budget -= $minutes;
            }

            if ($studyIndex % 2 === 1 && $practised !== [] && $budget >= 10) {
                arsort($practised);
                $slug = array_key_first($practised);
                $push($this->practiceItem($slug, 'revision', 10, 'scheduled_revision', $inputs, $scale, $locale, $languageName));
                $remaining[$slug] -= 10;
                $budget -= 10;
            }

            while ($budget >= 10) {
                $slug = $this->pickSkill($remaining, $usedToday, $allocations);
                $block = match (true) {
                    $budget >= 20 && $remaining[$slug] >= 20 => 20,
                    $budget >= 15 && $remaining[$slug] >= 15 => 15,
                    default => 10,
                };
                if ($budget - $block < 10) {
                    $block = $budget; // absorb the remainder into the last block
                }

                $topic = null;
                if ($slug === 'grammar' && ($t = array_shift($topics))) {
                    $topic = $t['topic'];
                }
                $push($this->practiceItem($slug, null, $block, $topic ? 'assessment_topic_weakness' : $allocations[$slug]['reason'], $inputs, $scale, $locale, $languageName, $topic));

                $remaining[$slug] -= $block;
                $practised[$slug] = ($practised[$slug] ?? 0) + $block;
                $usedToday[$slug] = true;
                $budget -= $block;
            }
            $studyIndex++;
        }

        return $items;
    }

    private function pickSkill(array $remaining, array $usedToday, array $allocations): string
    {
        $candidates = array_filter($remaining, fn ($m, $slug) => ! isset($usedToday[$slug]), ARRAY_FILTER_USE_BOTH) ?: $remaining;
        // Largest remaining allocation first; ties broken by priority order (allocations are pre-sorted).
        $best = null;
        foreach (array_keys($allocations) as $slug) {
            if (! array_key_exists($slug, $candidates)) {
                continue;
            }
            if ($best === null || $candidates[$slug] > $candidates[$best]) {
                $best = $slug;
            }
        }

        return $best;
    }

    private function practiceItem(string $slug, ?string $activity, int $minutes, string $reason, PlanInputs $inputs, CefrScale $scale, string $locale, string $languageName, ?string $topic = null): array
    {
        $ordinal = $inputs->skills[$slug]['ordinal'] ?? $inputs->overallOrdinal ?? 1;
        $level = $scale->byOrdinal($ordinal);
        $activity ??= self::ACTIVITY_BY_SKILL[$slug];

        return [
            'skill_id' => $inputs->skills[$slug]['skill_id'],
            'activity_type' => $activity,
            'topic' => $topic,
            'estimated_minutes' => $minutes,
            'reason_code' => $reason,
            'target_level_id' => $level->id,
            // Snapshot in the generation locale; the API re-renders in the reader's locale.
            'title' => $this->text->itemTitle($activity, $slug, $level->label, $topic, $languageName, $locale),
            'objective' => $this->text->itemObjective($activity, $slug, $level->label, $level->ordinal, $topic, $languageName, $locale),
        ];
    }
}
