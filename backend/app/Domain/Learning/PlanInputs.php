<?php

namespace App\Domain\Learning;

use App\Models\AssessmentAttempt;
use App\Models\LearnerProfile;
use App\Models\Skill;

/**
 * Immutable snapshot of the evidence a plan version is generated from. Stored on the version for auditability.
 */
final class PlanInputs
{
    /**
     * @param  array<string, array{skill_id:int, ordinal:?int, confidence:?string, assessed:bool, weight:float, focus:bool}>  $skills
     * @param  list<int>  $studyDays
     * @param  list<array{skill:string, topic:string}>  $weakTopics
     */
    public function __construct(
        public readonly array $skills,
        public readonly ?int $overallOrdinal,
        public readonly bool $overallProvisional,
        public readonly int $targetOrdinal,
        public readonly int $dailyMinutes,
        public readonly array $studyDays,
        public readonly array $weakTopics,
        public readonly bool $hasCompletedAssessment,
        public readonly string $purpose,
        public readonly string $timezone,
    ) {}

    public static function fromProfile(LearnerProfile $profile): self
    {
        $profile->loadMissing(['skillProfiles.level', 'focusSkills', 'scheduleSlots', 'purpose.skillWeights.skill', 'selfReportedLevel', 'targetLevel', 'user.profile']);

        $latest = $profile->assessmentAttempts()
            ->where('status', AssessmentAttempt::STATUS_COMPLETED)
            ->latest('completed_at')
            ->with(['overallLevel', 'findings.skill'])
            ->first();

        $overall = $latest?->overallLevel?->ordinal ?? $profile->selfReportedLevel?->ordinal;
        $weights = $profile->purpose->skillWeights->mapWithKeys(fn ($w) => [$w->skill->slug => (float) $w->weight]);
        $focus = $profile->focusSkills->pluck('slug')->all();

        $skills = [];
        foreach (Skill::orderBy('sort_order')->get() as $skill) {
            $sp = $profile->skillProfiles->firstWhere('skill_id', $skill->id);
            $assessed = $sp?->status === 'assessed';
            $skills[$skill->slug] = [
                'skill_id' => $skill->id,
                'ordinal' => $assessed ? $sp->level?->ordinal : null,
                'confidence' => $assessed ? $sp->confidence : null,
                'assessed' => $assessed,
                'weight' => $weights[$skill->slug] ?? 1.0,
                'focus' => in_array($skill->slug, $focus, true),
            ];
        }

        $weakTopics = $latest
            ? $latest->findings->where('code', 'topic_unexpected_errors')
                ->map(fn ($f) => ['skill' => $f->skill?->slug ?? 'grammar', 'topic' => $f->detail])->values()->all()
            : [];

        $days = $profile->scheduleSlots->pluck('day_of_week')->map(fn ($d) => (int) $d)->sort()->values()->all();
        $baseline = $overall ?? 1;

        return new self(
            skills: $skills,
            overallOrdinal: $overall,
            overallProvisional: $latest?->overall_is_provisional ?? true,
            targetOrdinal: $profile->targetLevel?->ordinal ?? min(6, $baseline + 1),
            dailyMinutes: $profile->daily_minutes,
            studyDays: $days ?: [0, 1, 2, 3, 4, 5, 6],
            weakTopics: $weakTopics,
            hasCompletedAssessment: $latest !== null,
            purpose: $profile->purpose->slug,
            timezone: $profile->user->profile?->timezone ?? 'UTC',
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
