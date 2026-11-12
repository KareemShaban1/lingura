<?php

namespace App\Http\Resources;

use App\Models\VocabularyWord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VocabularyWord */
class WordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['translations', 'examples', 'relations', 'level']);

        return [
            'id' => $this->id,
            'lemma' => $this->lemma,
            'part_of_speech' => $this->part_of_speech,
            'gender' => $this->gender,
            'level' => $this->level?->label,
            'meaning' => $this->translate('meaning'),
            'examples' => $this->examples->map(fn ($e) => ['sentence' => $e->sentence, 'translation' => $e->translation]),
            'synonyms' => $this->relations->where('type', 'synonym')->pluck('value')->values(),
            'antonyms' => $this->relations->where('type', 'antonym')->pluck('value')->values(),
        ];
    }
}
