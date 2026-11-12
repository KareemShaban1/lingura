<?php

namespace App\Jobs;

use App\Domain\AI\AiGateway;
use App\Domain\AI\Data\SpeechRequest;
use App\Models\AssessmentItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Generates listening audio once per item via the TTS capability and caches it in private storage.
 * Audio is shared by all learners (not per-user), which keeps TTS cost proportional to the item bank size.
 */
class SynthesizeItemAudio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $itemId)
    {
        $this->onQueue('audio');
    }

    public function handle(AiGateway $ai): void
    {
        $item = AssessmentItem::with(['language', 'level'])->findOrFail($this->itemId);
        if ($item->audio_path || ! $item->stimulus_text) {
            return;
        }

        // Slower delivery for lower levels, natural pace for advanced levels.
        $pace = $item->level->ordinal <= 2 ? 'slowly and very clearly' : ($item->level->ordinal <= 4 ? 'at a moderate, natural pace' : 'at a natural conversational pace');
        $audio = $ai->speech(new SpeechRequest(
            operation: 'tts.assessment_item',
            text: $item->stimulus_text,
            languageCode: $item->language->code,
            instructions: "Read this {$item->language->name} text {$pace}, as a native speaker would.",
        ));

        $path = "assessment-audio/{$item->language->code}/{$item->public_id}.{$audio->extension}";
        Storage::disk(config('learning.media.disk'))->put($path, $audio->bytes);
        $item->update(['audio_path' => $path]);
    }
}
