<?php

namespace App\Http\Resources;

use App\Domain\Learning\PlanText;
use App\Models\LearningPlanVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LearningPlanVersion */
class PlanVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'version_number' => $this->version_number,
            'generator' => $this->generator,
            'trigger' => $this->trigger,
            'daily_minutes' => $this->daily_minutes,
            'valid_from' => $this->valid_from->toDateString(),
            'valid_until' => $this->valid_until->toDateString(),
            'rationale' => app(PlanText::class)->forVersion($this->resource),
            'created_at' => $this->created_at->toIso8601String(),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'skill' => $a->skill->slug,
                'minutes_per_week' => $a->minutes_per_week,
                'priority_score' => $a->priority_score,
                'reason_code' => $a->reason_code,
                'reason' => __("plan.reasons.{$a->reason_code}"),
            ])),
            'items_completed' => $this->whenCounted('completed_items'),
            'items_total' => $this->whenCounted('items'),
        ];
    }
}
