<?php

namespace App\Http\Resources;

use App\Domain\Vocabulary\VocabularyService;
use App\Models\UserVocabulary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserVocabulary */
class UserVocabularyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'word' => new WordResource($this->word),
            'source' => $this->source,
            'state' => $this->state,
            'mastered' => $this->state === 'review' && $this->stability >= VocabularyService::MASTERED_STABILITY_DAYS,
            'stability_days' => $this->stability !== null ? round($this->stability, 1) : null,
            'reps' => $this->reps,
            'lapses' => $this->lapses,
            'due_at' => $this->due_at->toIso8601String(),
            'is_due' => $this->due_at->lte(now()),
            'last_reviewed_at' => $this->last_reviewed_at?->toIso8601String(),
            'collections' => $this->whenLoaded('collections', fn () => $this->collections->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])),
        ];
    }
}
