<?php

namespace App\Jobs;

use App\Domain\AI\AiGateway;
use App\Domain\AI\Data\SpeechRequest;
use App\Models\ExamForm;
use App\Models\ExamItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/** One recording per listening group (shared by its questions), read at a natural exam pace. */
class SynthesizeExamAudio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $formId)
    {
        $this->onQueue('audio');
    }

    public function handle(AiGateway $ai): void
    {
        $form = ExamForm::with('framework.language')->findOrFail($this->formId);
        $language = $form->framework->language;
        $groups = ExamItem::where('exam_form_id', $form->id)->whereNull('audio_path')
            ->whereHas('section.skill', fn ($q) => $q->where('slug', 'listening'))->get()->groupBy('group_key');

        foreach ($groups as $key => $items) {
            $audio = $ai->speech(new SpeechRequest(
                operation: 'tts.exam',
                text: $items->first()->stimulus_text,
                languageCode: $language->code,
                instructions: "Read this {$language->name} recording at a natural pace, as in a listening exam. Use different voices' intonation for dialogue turns.",
            ));
            $path = "exam-audio/{$language->code}/{$form->public_id}-{$key}.{$audio->extension}";
            Storage::disk(config('learning.media.disk'))->put($path, $audio->bytes);
            ExamItem::whereIn('id', $items->pluck('id'))->update(['audio_path' => $path]);
        }
    }
}
