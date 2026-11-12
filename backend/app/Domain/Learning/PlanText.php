<?php

namespace App\Domain\Learning;

use App\Domain\Assessment\Engine\CefrScale;
use App\Models\CefrLevel;
use App\Models\ConversationScenario;
use App\Models\Exercise;
use App\Models\GrammarTopic;
use App\Models\Language;
use App\Models\LearningPlanItem;
use App\Models\LearningPlanVersion;
use App\Models\Lesson;
use App\Models\ProductionTask;
use Illuminate\Support\Str;

/**
 * Single source for learner-facing plan text. Text is rendered in the *reader's* locale at read time from
 * structured fields (activity, skill, level, topic, bound content), so switching interface language
 * re-localises existing plans. Text stored at generation time is kept only as a historical snapshot.
 */
class PlanText
{
    /** @return array{title: string, objective: string} */
    public function render(string $activity, ?string $skill, ?CefrLevel $level, ?string $topic, Language $language, ?Lesson $lesson = null, ?Exercise $exercise = null, ?string $locale = null, ?ProductionTask $task = null, ?ConversationScenario $scenario = null): array
    {
        if ($scenario) {
            $scenario->loadMissing('translations');

            return [
                'title' => __('plan.items.conversation.title', ['name' => $scenario->translate('title', $locale)], $locale),
                'objective' => __('plan.items.conversation.objective', ['language' => $language->name, 'level' => $level?->label ?? ''], $locale),
            ];
        }
        if ($task) {
            $task->loadMissing('translations');

            return [
                'title' => __('plan.items.writing_task.title', ['name' => $task->translate('title', $locale)], $locale),
                'objective' => __('plan.items.writing_task.objective', ['min' => $task->min_words, 'max' => $task->max_words], $locale),
            ];
        }
        $params = [
            'level' => $level?->label ?? '',
            'language' => $language->name,
            'skill' => $skill ? __("plan.skills.{$skill}", [], $locale) : '',
            'topic' => $topic ? $this->topicName($language, $topic, $locale) : '',
        ];
        $band = ($level?->ordinal ?? 1) <= 2 ? 'a' : (($level?->ordinal ?? 1) <= 4 ? 'b' : 'c');

        if ($lesson) {
            $lesson->loadMissing('translations');

            return [
                'title' => __('plan.items.lesson.title', ['title' => $lesson->translate('title', $locale)], $locale),
                'objective' => (string) $lesson->translate('intro', $locale),
            ];
        }
        if ($exercise) {
            $exercise->loadMissing(['translations', 'grammarTopic.translations']);
            $name = $exercise->translate('title', $locale)
                ?? ($exercise->grammarTopic ? $exercise->grammarTopic->translate('title', $locale) : null)
                ?? __("plan.skills.{$skill}", [], $locale);

            return [
                'title' => __("plan.exercise_types.{$exercise->type}", ['name' => $name], $locale),
                'objective' => __("plan.content.{$exercise->type}", $params, $locale),
            ];
        }

        return [
            'title' => match (true) {
                $activity === 'placement_assessment', $activity === 'vocabulary_review' => __("plan.items.{$activity}.title", [], $locale),
                $topic !== null => __('plan.items.grammar_topic.title', $params, $locale),
                default => __("plan.items.{$activity}.title", $params, $locale),
            },
            'objective' => match (true) {
                $activity === 'placement_assessment', $activity === 'vocabulary_review' => __("plan.items.{$activity}.objective", [], $locale),
                $activity === 'revision' => __('plan.items.revision.objective', $params, $locale),
                $topic !== null => __('plan.items.grammar_topic.objective', $params, $locale),
                default => __("plan.objectives.{$skill}.{$band}", $params, $locale),
            },
        ];
    }

    /** @return array{title: string, objective: string} */
    public function forItem(LearningPlanItem $item, Language $language, ?string $locale = null): array
    {
        $item->loadMissing(['skill', 'targetLevel', 'lesson', 'exercise', 'productionTask', 'scenario']);

        return $this->render($item->activity_type, $item->skill?->slug, $item->targetLevel, $item->topic, $language, $item->lesson, $item->exercise, $locale, $item->productionTask, $item->scenario);
    }

    public function topicName(Language $language, string $slug, ?string $locale = null): string
    {
        static $cache = [];
        $key = $language->id.':'.$slug;
        $cache[$key] ??= GrammarTopic::where('language_id', $language->id)->where('slug', $slug)->with('translations')->first() ?? false;

        return $cache[$key] ? (string) $cache[$key]->translate('title', $locale) : Str::of($slug)->replace('_', ' ')->toString();
    }

    /** @param array<string, array{minutes:int, reason:string}> $allocations ordered by priority */
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
}
