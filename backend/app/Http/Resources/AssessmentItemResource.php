<?php

namespace App\Http\Resources;

use App\Models\AssessmentItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Learner-facing item: never includes the answer key, difficulty or explanation.
 *
 * @mixin AssessmentItem
 */
class AssessmentItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'skill' => $this->skill->slug,
            'format' => $this->format,
            'stimulus' => $this->format === 'listening_mcq' ? null : $this->stimulus_text, // listening transcript is withheld
            'has_audio' => $this->audio_path !== null,
            'prompt' => $this->prompt,
            'options' => $this->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label])->values(),
        ];
    }
}
