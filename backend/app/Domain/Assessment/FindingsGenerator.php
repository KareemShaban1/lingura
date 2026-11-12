<?php

namespace App\Domain\Assessment;

use App\Domain\Assessment\Engine\CefrScale;
use App\Domain\Assessment\Engine\IrtModel;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentAttemptSection;

/**
 * Rule set findings_v1 (docs/09-assessment-methodology.md §8). Deterministic and explainable.
 */
class FindingsGenerator
{
    public function generate(AssessmentAttempt $attempt, ?int $overallOrdinal, CefrScale $scale): void
    {
        $attempt->findings()->delete();
        $irt = IrtModel::fromConfig();
        $position = 0;
        $add = function (string $type, string $code, ?int $skillId = null, ?string $detail = null) use ($attempt, &$position) {
            $attempt->findings()->create([
                'type' => $type, 'code' => $code, 'skill_id' => $skillId, 'detail' => $detail, 'position' => $position++,
            ]);
        };

        $sections = $attempt->sections()->with(['skill', 'answers.item.level'])->get();
        $weakSkills = [];

        foreach ($sections->where('status', AssessmentAttemptSection::COMPLETED) as $section) {
            $ordinal = $scale->levelForTheta($section->theta)->ordinal;
            if ($overallOrdinal !== null && $ordinal >= $overallOrdinal + 1) {
                $add('strength', 'skill_above_overall', $section->skill_id);
            }
            if ($overallOrdinal !== null && $ordinal <= $overallOrdinal - 1) {
                $add('weakness', 'skill_below_overall', $section->skill_id);
                $weakSkills[$section->skill_id] = true;
            } elseif ($irt->confidence($section->standard_error) === 'low') {
                $add('weakness', 'low_confidence_estimate', $section->skill_id);
                $weakSkills[$section->skill_id] = true;
            }

            // Unexpected errors: incorrect answers on items at or below the learner's estimated level in that skill.
            $topics = $section->answers
                ->filter(fn ($a) => ! $a->is_correct && $a->item->grammar_topic_slug && $a->item->level->ordinal <= $ordinal)
                ->countBy(fn ($a) => $a->item->grammar_topic_slug)
                ->sortDesc();
            foreach ($topics as $topic => $count) {
                $add('weakness', 'topic_unexpected_errors', $section->skill_id, $topic);
                $add('recommendation', 'review_topic', $section->skill_id, $topic);
            }
        }

        foreach (array_keys($weakSkills) as $skillId) {
            $add('recommendation', 'prioritise_skill', $skillId);
        }

        foreach ($sections->whereIn('status', [AssessmentAttemptSection::SKIPPED, AssessmentAttemptSection::NOT_AVAILABLE]) as $section) {
            $code = $section->skip_reason === 'coming_in_future_release' ? 'section_coming_soon' : 'complete_section_later';
            $add('recommendation', $code, $section->skill_id, $section->skip_reason);
        }

        $add('recommendation', 'follow_learning_plan');
    }
}
