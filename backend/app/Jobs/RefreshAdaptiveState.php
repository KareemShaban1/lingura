<?php

namespace App\Jobs;

use App\Domain\Adaptive\AdaptiveEngine;
use App\Models\LearnerProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Recomputes mistake patterns and adaptive signals after new evidence (deduplicated per learner for 60 s). */
class RefreshAdaptiveState implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $learnerProfileId) {}

    public function uniqueId(): string
    {
        return (string) $this->learnerProfileId;
    }

    public function handle(AdaptiveEngine $engine): void
    {
        $profile = LearnerProfile::find($this->learnerProfileId);
        if ($profile) {
            $engine->refresh($profile);
        }
    }
}
