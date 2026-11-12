<?php

namespace App\Jobs;

use App\Domain\AI\AiGateway;
use App\Domain\AI\Data\SpeechRequest;
use App\Models\Exercise;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Generates shared (not per-user) audio for listening passages and dictation sentences.
 * Lower levels are read more slowly; learners can additionally slow playback in the player.
 */
class SynthesizeExerciseAudio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $exerciseId)
    {
        $this->onQueue('audio');
    }

    public function handle(AiGateway $ai): void
    {
        $exercise = Exercise::with(['language', 'level', 'questions.acceptedAnswers'])->findOrFail($this->exerciseId);
        $disk = Storage::disk(config('learning.media.disk'));
        $pace = $exercise->level->ordinal <= 2 ? 'slowly and very clearly' : 'at a natural pace';
        $speak = fn (string $text) => $ai->speech(new SpeechRequest(
            operation: 'tts.exercise',
            text: $text,
            languageCode: $exercise->language->code,
            instructions: "Read this {$exercise->language->name} text {$pace}, as a native speaker would.",
        ));

        if ($exercise->type === 'listening' && ! $exercise->audio_path && $exercise->stimulus_text) {
            $audio = $speak($exercise->stimulus_text);
            $path = "exercise-audio/{$exercise->language->code}/{$exercise->public_id}.{$audio->extension}";
            $disk->put($path, $audio->bytes);
            $exercise->update(['audio_path' => $path]);
        }

        if ($exercise->type === 'dictation') {
            foreach ($exercise->questions as $question) {
                if ($question->audio_path || ! $question->acceptedAnswers->first()) {
                    continue;
                }
                $audio = $speak($question->acceptedAnswers->first()->answer);
                $path = "exercise-audio/{$exercise->language->code}/{$exercise->public_id}-{$question->position}.{$audio->extension}";
                $disk->put($path, $audio->bytes);
                $question->update(['audio_path' => $path]);
            }
        }
    }
}
