<?php

namespace App\Http\Resources;

use App\Models\LearningSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LearningSession */
class LearningSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'started_at' => $this->started_at->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'active_seconds' => $this->active_seconds,
            'outcome' => $this->outcome,
            'self_rating' => $this->self_rating,
            'learner_profile_id' => $this->learnerProfile->public_id,
            'target_language' => [
                'code' => $this->learnerProfile->targetLanguage->code,
                'name' => $this->learnerProfile->targetLanguage->name,
            ],
            'plan_item' => $this->planItem ? new PlanItemResource($this->planItem->loadMissing(['skill', 'targetLevel'])) : null,
        ];
    }
}
