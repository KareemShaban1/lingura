<?php

namespace App\Listeners;

use App\Domain\Learning\PlanGenerator;
use App\Events\AssessmentCompleted;

/** New evidence → new plan version (the previous version stays in history). */
class RegeneratePlanAfterAssessment
{
    public function __construct(private readonly PlanGenerator $generator) {}

    public function handle(AssessmentCompleted $event): void
    {
        $this->generator->generate($event->attempt->learnerProfile, 'assessment');
    }
}
