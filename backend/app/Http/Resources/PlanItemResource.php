<?php

namespace App\Http\Resources;

use App\Domain\Learning\PlanText;
use App\Models\LearningPlanItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LearningPlanItem */
class PlanItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['skill', 'targetLevel', 'lesson', 'exercise', 'productionTask', 'scenario', 'version.plan.learnerProfile.targetLanguage']);
        // Rendered in the reader's current locale from structured fields.
        $text = app(PlanText::class)->forItem($this->resource, $this->version->plan->learnerProfile->targetLanguage);

        return [
            'id' => $this->public_id,
            'scheduled_date' => $this->scheduled_date->toDateString(),
            'position' => $this->position,
            'skill' => $this->skill?->slug,
            'activity_type' => $this->activity_type,
            'topic' => $this->topic,
            'lesson_id' => $this->lesson?->public_id,
            'exercise_id' => $this->exercise?->public_id,
            'writing_task_id' => $this->productionTask?->public_id,
            'scenario' => $this->scenario?->slug,
            'title' => $text['title'],
            'objective' => $text['objective'],
            'target_level' => $this->targetLevel ? new LevelResource($this->targetLevel) : null,
            'estimated_minutes' => $this->estimated_minutes,
            'reason_code' => $this->reason_code,
            'reason' => __("plan.reasons.{$this->reason_code}"),
            'status' => $this->status,
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
