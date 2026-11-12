<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Catalogue entry for an exercise (no questions). @mixin \App\Models\Exercise */
class ExerciseSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['translations', 'grammarTopic.translations', 'skill', 'level', 'questions']);

        return [
            'id' => $this->public_id,
            'type' => $this->type,
            'skill' => $this->skill->slug,
            'level' => new LevelResource($this->level),
            'title' => $this->translate('title') ?? $this->grammarTopic?->translate('title'),
            'grammar_topic' => $this->grammarTopic?->slug,
            'questions_count' => $this->questions->count(),
            'estimated_minutes' => (int) ceil($this->estimated_seconds / 60),
            'available' => $this->isAvailable(),
            'last_score' => $this->when(isset($this->last_score), fn () => $this->last_score !== null ? (float) $this->last_score : null),
        ];
    }
}
