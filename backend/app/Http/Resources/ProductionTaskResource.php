<?php

namespace App\Http\Resources;

use App\Models\ProductionTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductionTask */
class ProductionTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['translations', 'level']);

        return [
            'id' => $this->public_id,
            'mode' => $this->mode,
            'type' => $this->type,
            'title' => $this->translate('title'),
            'prompt' => $this->prompt,
            'source_text' => $this->source_text,
            'level' => new LevelResource($this->level),
            'min_words' => $this->min_words,
            'max_words' => $this->max_words,
        ];
    }
}
