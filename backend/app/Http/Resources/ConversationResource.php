<?php

namespace App\Http\Resources;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiConversation */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['tutor.translations', 'scenario.translations', 'level', 'learnerProfile.targetLanguage', 'report']);
        $hideCorrections = $this->tutor->correction_mode === 'end_only' && $this->status === 'active';

        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'mode' => $this->mode,
            'tutor' => ['slug' => $this->tutor->slug, 'name' => $this->tutor->name, 'voice' => $this->tutor->voice, 'correction_mode' => $this->tutor->correction_mode],
            'scenario' => $this->scenario ? ['slug' => $this->scenario->slug, 'title' => $this->scenario->translate('title')] : null,
            'level' => new LevelResource($this->level),
            'language' => ['code' => $this->learnerProfile->targetLanguage->code, 'direction' => $this->learnerProfile->targetLanguage->direction, 'name' => $this->learnerProfile->targetLanguage->name],
            'learner_turns' => $this->learner_turns,
            'started_at' => $this->started_at->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'has_report' => $this->report !== null,
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn (AiMessage $m) => self::message($m, $hideCorrections))->values()),
        ];
    }

    public static function message(AiMessage $m, bool $hideCorrections = false): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'source' => $m->source,
            'natural_alternative' => $hideCorrections ? null : $m->natural_alternative,
            'corrections' => $hideCorrections ? [] : $m->feedback->map(fn ($f) => [
                'type' => $f->type, 'original' => $f->original, 'correction' => $f->correction, 'explanation' => $f->explanation, 'topic' => $f->topic_slug,
            ])->values(),
            'created_at' => $m->created_at->toIso8601String(),
        ];
    }
}
