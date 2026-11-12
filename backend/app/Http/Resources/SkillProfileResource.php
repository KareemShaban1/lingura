<?php

namespace App\Http\Resources;

use App\Domain\Assessment\Engine\IrtModel;
use App\Models\SkillProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SkillProfile */
class SkillProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $irt = IrtModel::fromConfig();

        return [
            'skill' => $this->skill->slug,
            'status' => $this->status,
            'level' => $this->level ? new LevelResource($this->level) : null,
            'confidence' => $this->confidence,
            'scaled_score' => $this->theta !== null ? $irt->scaledScore($this->theta) : null,
            'source' => $this->source,
            'assessed_at' => $this->assessed_at?->toIso8601String(),
            'history' => $this->whenLoaded('scores', fn () => $this->scores->map(fn ($s) => [
                'recorded_at' => $s->recorded_at->toIso8601String(),
                'scaled_score' => $s->scaled_score,
                'level' => $s->level->label,
                'standard_error' => $s->standard_error,
                'evidence_count' => $s->evidence_count,
            ])),
        ];
    }
}
