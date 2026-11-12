<?php

namespace App\Domain\Assessment;

use App\Domain\Assessment\Engine\CefrScale;
use App\Domain\Assessment\Engine\IrtModel;
use App\Domain\Shared\LearningEventRecorder;
use App\Events\AssessmentCompleted;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentAttemptSection;
use App\Models\LearnerProfile;
use App\Models\Skill;
use App\Models\SkillProfile;
use Illuminate\Support\Facades\DB;

/**
 * Turns a finished attempt into independent skill profiles, append-only score history,
 * an overall level (lower median — never an average) and explainable findings.
 */
class AttemptFinalizer
{
    public function __construct(
        private readonly FindingsGenerator $findings,
        private readonly LearningEventRecorder $events,
    ) {}

    public function finalize(AssessmentAttempt $attempt): AssessmentAttempt
    {
        $finalized = DB::transaction(function () use ($attempt) {
            $attempt = AssessmentAttempt::whereKey($attempt->id)->lockForUpdate()->first();
            if ($attempt->status !== AssessmentAttempt::STATUS_IN_PROGRESS) {
                return null; // already finalised (idempotent)
            }

            $scale = CefrScale::load();
            $irt = IrtModel::fromConfig();
            $profile = $attempt->learnerProfile;
            $sections = $attempt->sections()->with(['skill', 'estimatedLevel'])->get();
            $completed = $sections->where('status', AssessmentAttemptSection::COMPLETED);

            foreach ($completed as $section) {
                $level = $scale->levelForTheta($section->theta);
                $confidence = $irt->confidence($section->standard_error);

                $skillProfile = SkillProfile::updateOrCreate(
                    ['learner_profile_id' => $profile->id, 'skill_id' => $section->skill_id],
                    [
                        'cefr_level_id' => $level->id,
                        'theta' => $section->theta,
                        'standard_error' => $section->standard_error,
                        'confidence' => $confidence,
                        'source' => 'placement',
                        'status' => SkillProfile::STATUS_ASSESSED,
                        'assessed_at' => now(),
                    ],
                );
                $skillProfile->scores()->create([
                    'assessment_attempt_id' => $attempt->id,
                    'theta' => $section->theta,
                    'standard_error' => $section->standard_error,
                    'scaled_score' => $irt->scaledScore($section->theta),
                    'cefr_level_id' => $level->id,
                    'evidence_count' => $section->items_answered,
                    'recorded_at' => now(),
                ]);
            }

            // Every skill has a profile row so "not yet assessed" is explicit rather than missing.
            foreach (Skill::all() as $skill) {
                SkillProfile::firstOrCreate(
                    ['learner_profile_id' => $profile->id, 'skill_id' => $skill->id],
                    ['status' => SkillProfile::STATUS_NOT_ASSESSED, 'source' => 'self_report'],
                );
            }

            $ordinals = $completed->map(fn ($s) => $scale->levelForTheta($s->theta)->ordinal)->values()->all();
            $overallOrdinal = $scale->lowerMedian($ordinals);
            $provisional = $this->provisionalReasons($completed, $irt) !== [];

            $attempt->update([
                'status' => AssessmentAttempt::STATUS_COMPLETED,
                'completed_at' => now(),
                'overall_level_id' => $overallOrdinal !== null ? $scale->byOrdinal($overallOrdinal)->id : null,
                'overall_is_provisional' => $provisional,
            ]);

            $this->findings->generate($attempt, $overallOrdinal, $scale);

            $profile->update(['status' => LearnerProfile::STATUS_ACTIVE]);
            $this->events->record($profile, 'assessment.completed', $attempt, value: $overallOrdinal, metadata: [
                'sections_completed' => $completed->count(),
            ]);

            return $attempt;
        });

        if ($finalized) {
            AssessmentCompleted::dispatch($finalized);
        }

        return ($finalized ?? $attempt)->fresh();
    }

    /** @return list<string> */
    public function provisionalReasons($completedSections, IrtModel $irt): array
    {
        $reasons = [];
        if ($completedSections->count() < 4) {
            $reasons[] = 'fewer_than_four_skills_assessed';
        }
        if ($completedSections->contains(fn ($s) => $irt->confidence($s->standard_error) === 'low')) {
            $reasons[] = 'low_confidence_skill';
        }
        if (! $completedSections->contains(fn ($s) => $s->skill->is_productive)) {
            $reasons[] = 'productive_skills_not_assessed';
        }

        return $reasons;
    }
}
