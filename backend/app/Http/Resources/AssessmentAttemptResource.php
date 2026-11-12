<?php

namespace App\Http\Resources;

use App\Models\AssessmentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssessmentAttempt */
class AssessmentAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['sections.skill', 'sections.section', 'sections.estimatedLevel', 'overallLevel', 'learnerProfile.targetLanguage']);

        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'engine_version' => $this->engine_version,
            'learner_profile_id' => $this->learnerProfile->public_id,
            'target_language' => [
                'code' => $this->learnerProfile->targetLanguage->code,
                'name' => $this->learnerProfile->targetLanguage->name,
                'direction' => $this->learnerProfile->targetLanguage->direction,
            ],
            'started_at' => $this->started_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'overall_level' => $this->overallLevel ? new LevelResource($this->overallLevel) : null,
            'overall_is_provisional' => $this->overall_is_provisional,
            'sections' => $this->sections->map(fn ($s) => [
                'skill' => $s->skill->slug,
                'status' => $s->status,
                'items_answered' => $s->items_answered,
                'max_items' => $s->section->max_items,
                'min_items' => $s->section->min_items,
                'reason' => $s->skip_reason,
            ]),
        ];
    }
}
