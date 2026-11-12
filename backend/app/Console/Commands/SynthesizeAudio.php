<?php

namespace App\Console\Commands;

use App\Domain\Languages\CapabilityMatrix;
use App\Jobs\SynthesizeItemAudio;
use App\Models\AssessmentItem;
use Illuminate\Console\Command;

class SynthesizeAudio extends Command
{
    protected $signature = 'lf:items:synthesize-audio {--language= : limit to one language code}';

    protected $description = 'Queue TTS generation for published listening items that have no audio yet';

    public function handle(CapabilityMatrix $capabilities): int
    {
        $items = AssessmentItem::published()->where('format', 'listening_mcq')->whereNull('audio_path')->with('language')
            ->when($this->option('language'), fn ($q, $code) => $q->whereHas('language', fn ($l) => $l->where('code', $code)))
            ->get();

        $queued = 0;
        foreach ($items as $item) {
            if (! $capabilities->isUsable($item->language->code, 'tts')) {
                $this->warn("skipped {$item->language->code}: TTS not supported");

                continue;
            }
            SynthesizeItemAudio::dispatch($item->id);
            $queued++;
        }
        $this->info("{$queued} audio jobs queued.");

        return self::SUCCESS;
    }
}
