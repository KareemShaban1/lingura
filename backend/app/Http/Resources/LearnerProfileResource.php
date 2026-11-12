<?php

namespace App\Http\Resources;

use App\Models\LearnerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LearnerProfile */
class LearnerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['targetLanguage', 'variant', 'purpose', 'selfReportedLevel', 'targetLevel', 'focusSkills', 'scheduleSlots', 'preferences']);

        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'target_language' => [
                'code' => $this->targetLanguage->code,
                'name' => $this->targetLanguage->name,
                'native_name' => $this->targetLanguage->native_name,
                'direction' => $this->targetLanguage->direction,
            ],
            'variant' => $this->variant ? ['code' => $this->variant->code, 'name' => $this->variant->name] : null,
            'purpose' => ['slug' => $this->purpose->slug, 'name' => $this->purpose->name],
            'self_reported_level' => $this->selfReportedLevel ? new LevelResource($this->selfReportedLevel) : null,
            'target_level' => $this->targetLevel ? new LevelResource($this->targetLevel) : null,
            'daily_minutes' => $this->daily_minutes,
            'focus_skills' => $this->focusSkills->pluck('slug'),
            'schedule' => $this->scheduleSlots->map(fn ($s) => ['day' => $s->day_of_week, 'time' => $s->preferred_time]),
            'preferences' => $this->preferences->pluck('value', 'preference_key'),
            'onboarded_at' => $this->onboarded_at?->toIso8601String(),
            'last_studied_at' => $this->last_studied_at?->toIso8601String(),
        ];
    }
}
