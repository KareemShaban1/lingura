<?php

namespace App\Http\Resources;

use App\Models\LearningPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LearningPlan */
class LearningPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'paused_at' => $this->paused_at?->toIso8601String(),
            'current_version' => $this->currentVersion ? new PlanVersionResource($this->currentVersion) : null,
        ];
    }
}
