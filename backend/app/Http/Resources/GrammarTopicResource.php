<?php

namespace App\Http\Resources;

use App\Domain\Courses\GrammarService;
use App\Models\GrammarTopic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GrammarTopic */
class GrammarTopicResource extends JsonResource
{
    public function __construct($resource, private readonly ?object $mastery = null, private readonly bool $detailed = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['translations', 'level']);
        $enough = $this->mastery && $this->mastery->answers_total >= GrammarService::MIN_EVIDENCE;

        return [
            'slug' => $this->slug,
            'title' => $this->translate('title'),
            'level' => new LevelResource($this->level),
            'exercises_count' => $this->exercises_count ?? null,
            'mastery' => $this->mastery ? [
                'value' => $enough ? round($this->mastery->mastery, 2) : null,
                'enough_evidence' => $enough,
                'answers_total' => $this->mastery->answers_total,
                'answers_correct' => $this->mastery->answers_correct,
                'last_practiced_at' => $this->mastery->last_practiced_at?->toIso8601String(),
            ] : null,
            'explanation' => $this->when($this->detailed, fn () => $this->translate('explanation')),
            'examples' => $this->when($this->detailed, fn () => $this->resource->loadMissing('examples')->examples->map(fn ($e) => ['sentence' => $e->sentence, 'translation' => $e->translation])),
        ];
    }
}
