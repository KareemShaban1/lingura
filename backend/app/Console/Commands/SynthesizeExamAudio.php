<?php

namespace App\Console\Commands;

use App\Domain\Languages\CapabilityMatrix;
use App\Jobs\SynthesizeExamAudio as Job;
use App\Models\ExamForm;
use Illuminate\Console\Command;

class SynthesizeExamAudio extends Command
{
    protected $signature = 'lf:exams:synthesize-audio';

    protected $description = 'Queue TTS for exam listening sections without recordings (until then those sections are not offered)';

    public function handle(CapabilityMatrix $capabilities): int
    {
        $queued = 0;
        foreach (ExamForm::where('is_active', true)->with('framework.language')->get() as $form) {
            if (! $capabilities->isUsable($form->framework->language->code, 'tts')) {
                $this->warn("skipped {$form->key}: TTS not supported");

                continue;
            }
            Job::dispatch($form->id);
            $queued++;
        }
        $this->info("{$queued} exam audio jobs queued.");

        return self::SUCCESS;
    }
}
