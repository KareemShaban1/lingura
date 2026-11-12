<?php

namespace App\Domain\Learning;

use App\Domain\Assessment\Engine\CefrScale;
use App\Models\LearningPlanItem;
use App\Models\LearningPlanVersion;
use Illuminate\Support\Str;

/**
 * Single source for learner-facing plan text. Text is rendered in the *reader's* locale at read time,
 * from the stored structured fields (activity type, skill, level, topic), so switching interface language
 * re-localises existing plans. The text stored at generation time is kept as a historical snapshot.
 */
class PlanText
{
    public function itemTitle(string $activity, ?string $skill, ?string $levelLabel, ?string $topic, string $languageName, ?string $locale = null): string
    {
        $params = $this->params($skill, $levelLabel, $topic, $languageName, $locale);

        return match (true) {
            $activity === 'placement_assessment' => __('plan.items.placement_assessment.title', [], $locale),
            $topic !== null => __('plan.items.grammar_topic.title', $params, $locale),
            default => __("plan.items.{$activity}.title", $params, $locale),
        };
    }

    public function itemObjective(string $activity, ?string $skill, ?string $levelLabel, ?int $levelOrdinal, ?string $topic, string $languageName, ?string $locale = null): string
    {
        $params = $this->params($skill, $levelLabel, $topic, $languageName, $locale);
        $band = ($levelOrdinal ?? 1) <= 2 ? 'a' : (($levelOrdinal ?? 1) <= 4 ? 'b' : 'c');

        return match (true) {
            $activity === 'placement_assessment' => __('plan.items.placement_assessment.objective', [], $locale),
            $activity === 'revision' => __('plan.items.revision.objective', $params, $locale),
            $topic !== null => __('plan.items.grammar_topic.objective', $params, $locale),
            default => __("plan.objectives.{$skill}.{$band}", $params, $locale),
        };
    }

    public function forItem(LearningPlanItem $item, string $languageName, ?string $locale = null): array
    {
        $item->loadMissing(['skill', 'targetLevel']);

        return [
            'title' => $this->itemTitle($item->activity_type, $item->skill?->slug, $item->targetLevel?->label, $item->topic, $languageName, $locale),
            'objective' => $this->itemObjective($item->activity_type, $item->skill?->slug, $item->targetLevel?->label, $item->targetLevel?->ordinal, $item->topic, $languageName, $locale),
        ];
    }

    /**
     * @param  array<string, array{minutes:int, reason:string}>  $allocations  ordered by priority
     */
    public function rationale(bool $hasAssessment, ?int $overallOrdinal, bool $provisional, int $dailyMinutes, int $studyDays, array $allocations, ?string $locale = null): string
    {
        $lines = [];
        $lines[] = $hasAssessment
            ? __('plan.rationale.based_on_assessment', [
                'level' => $overallOrdinal !== null ? CefrScale::load()->byOrdinal($overallOrdinal)->label : '—',
                'provisional' => $provisional ? __('plan.rationale.provisional', [], $locale) : '',
            ], $locale)
            : __('plan.rationale.no_assessment', [], $locale);
        $lines[] = __('plan.rationale.time', ['minutes' => $dailyMinutes, 'days' => $studyDays], $locale);

        foreach (array_slice($allocations, 0, 2, true) as $slug => $a) {
            $lines[] = __('plan.rationale.top_skill', [
                'skill' => __("plan.skills.{$slug}", [], $locale),
                'minutes' => $a['minutes'],
                'reason' => __("plan.reasons.{$a['reason']}", [], $locale),
            ], $locale);
        }

        return implode(' ', $lines);
    }

    public function forVersion(LearningPlanVersion $version, ?string $locale = null): string
    {
        $version->loadMissing('allocations.skill');
        $inputs = $version->inputs_snapshot;
        $allocations = $version->allocations->sortByDesc('priority_score')
            ->mapWithKeys(fn ($a) => [$a->skill->slug => ['minutes' => $a->minutes_per_week, 'reason' => $a->reason_code]])
            ->all();

        return $this->rationale(
            (bool) ($inputs['hasCompletedAssessment'] ?? false),
            $inputs['overallOrdinal'] ?? null,
            (bool) ($inputs['overallProvisional'] ?? true),
            (int) $version->daily_minutes,
            count($inputs['studyDays'] ?? []),
            $allocations,
            $locale,
        );
    }

    private function params(?string $skill, ?string $levelLabel, ?string $topic, string $languageName, ?string $locale): array
    {
        return [
            'level' => $levelLabel ?? '',
            'language' => $languageName,
            'skill' => $skill ? __("plan.skills.{$skill}", [], $locale) : '',
            'topic' => $topic ? Str::of($topic)->replace('_', ' ')->toString() : '',
        ];
    }
}
