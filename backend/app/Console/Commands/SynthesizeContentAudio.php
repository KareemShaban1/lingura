<?php

namespace App\Console\Commands;

use App\Domain\Languages\CapabilityMatrix;
use App\Jobs\SynthesizeExerciseAudio;
use App\Models\Exercise;
use Illuminate\Console\Command;

class SynthesizeContentAudio extends Command
{
    protected $signature = 'lf:content:synthesize-audio {--language= : limit to one language code}';

    protected $description = 'Queue TTS generation for listening and dictation exercises that have no audio yet';

    public function handle(CapabilityMatrix $capabilities): int
    {
        $exercises = Exercise::published()->whereIn('type', Exercise::AUDIO_TYPES)->with(['language', 'questions'])
            ->when($this->option('language'), fn ($q, $code) => $q->whereHas('language', fn ($l) => $l->where('code', $code)))
            ->get()->reject->isAvailable();

        $queued = 0;
        foreach ($exercises as $exercise) {
            if (! $capabilities->isUsable($exercise->language->code, 'tts')) {
                $this->warn("skipped {$exercise->key}: TTS not supported for {$exercise->language->code}");

                continue;
            }
            SynthesizeExerciseAudio::dispatch($exercise->id);
            $queued++;
        }
        $this->info("{$queued} exercise audio jobs queued.");

        return self::SUCCESS;
    }
}
