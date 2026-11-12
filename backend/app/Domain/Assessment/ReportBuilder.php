<?php

namespace App\Domain\Assessment;

use App\Domain\Assessment\Engine\IrtModel;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentAttemptSection;
use Illuminate\Support\Str;

/** Learner-facing assessment report. Every number is traceable to stored answers. */
class ReportBuilder
{
    public function __construct(private readonly AttemptFinalizer $finalizer) {}

    public function build(AssessmentAttempt $attempt): array
    {
        $attempt->loadMissing(['sections.skill', 'sections.estimatedLevel', 'overallLevel', 'findings.skill']);
        $irt = IrtModel::fromConfig();
        $completed = $attempt->sections->where('status', AssessmentAttemptSection::COMPLETED);

        return [
            'attempt_id' => $attempt->public_id,
            'status' => $attempt->status,
            'engine_version' => $attempt->engine_version,
            'completed_at' => $attempt->completed_at?->toIso8601String(),
            'is_official' => false,
            'disclaimer' => 'These results are estimates produced by an adaptive placement test aligned to CEFR descriptors. They are not an official language certification.',
            'overall' => [
                'level' => $attempt->overallLevel ? ['code' => $attempt->overallLevel->code, 'label' => $attempt->overallLevel->label, 'ordinal' => $attempt->overallLevel->ordinal] : null,
                'is_provisional' => $attempt->overall_is_provisional,
                'provisional_reasons' => $this->finalizer->provisionalReasons($completed, $irt),
                'method' => 'lower_median_of_assessed_skills',
            ],
            'skills' => $attempt->sections->map(fn (AssessmentAttemptSection $s) => [
                'skill' => $s->skill->slug,
                'status' => $s->status,
                'reason' => $s->skip_reason,
                'level' => $s->estimatedLevel ? ['code' => $s->estimatedLevel->code, 'label' => $s->estimatedLevel->label, 'ordinal' => $s->estimatedLevel->ordinal] : null,
                'confidence' => $s->status === AssessmentAttemptSection::COMPLETED ? $irt->confidence($s->standard_error) : null,
                'scaled_score' => $s->status === AssessmentAttemptSection::COMPLETED ? $irt->scaledScore($s->theta) : null,
                'standard_error' => $s->standard_error,
                'items_answered' => $s->items_answered,
                'items_correct' => $s->items_correct,
            ])->values(),
            'findings' => $attempt->findings->map(fn ($f) => [
                'type' => $f->type,
                'code' => $f->code,
                'skill' => $f->skill?->slug,
                'detail' => $f->detail,
                'detail_label' => $f->detail ? Str::of($f->detail)->replace('_', ' ')->ucfirst()->toString() : null,
            ])->values(),
        ];
    }
}
