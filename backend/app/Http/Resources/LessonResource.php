<?php

namespace App\Http\Resources;

use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin Lesson */
class LessonResource extends JsonResource
{
    /** @param array{status?: string, best_score?: ?float, attempts?: Collection}|null $progress */
    public function __construct($resource, private readonly ?array $progress = null, private readonly bool $detailed = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['translations', 'skill', 'level']);
        $base = [
            'id' => $this->public_id,
            'title' => $this->translate('title'),
            'skill' => $this->skill->slug,
            'level' => new LevelResource($this->level),
            'estimated_minutes' => $this->estimated_minutes,
            'status' => $this->progress['status'] ?? 'not_started',
            'best_score' => $this->progress['best_score'] ?? null,
        ];
        if (! $this->detailed) {
            return $base;
        }

        $this->resource->loadMissing(['grammarTopic.translations', 'grammarTopic.examples', 'objectives.translations', 'words', 'exercises.questions', 'unit.course.language']);
        $attempts = $this->progress['attempts'] ?? collect();

        return $base + [
            'intro' => $this->translate('intro'),
            'language' => ['code' => $this->unit->course->language->code, 'direction' => $this->unit->course->language->direction],
            'objectives' => $this->objectives->map(fn ($o) => $o->translate('description'))->values(),
            'grammar_topic' => $this->grammarTopic ? (new GrammarTopicResource($this->grammarTopic, detailed: true))->toArray($request) : null,
            'words' => WordResource::collection($this->words),
            'exercises' => $this->exercises->map(fn ($e) => [
                ...(new ExerciseSummaryResource($e))->toArray($request),
                'completed' => $attempts->has($e->id),
                'score' => $attempts->get($e->id)?->score,
            ])->values(),
        ];
    }
}
