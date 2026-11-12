<?php

namespace App\Jobs;

use App\Domain\Adaptive\AdaptiveEngine;
use App\Domain\Billing\QuotaGuard;
use App\Domain\Practice\ProgressRecorder;
use App\Domain\Shared\LearningEventRecorder;
use App\Domain\Writing\WritingEvaluator;
use App\Models\Skill;
use App\Models\WritingSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class EvaluateWriting implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [20];

    public function __construct(public readonly int $submissionId)
    {
        $this->onQueue('ai');
    }

    public function handle(WritingEvaluator $evaluator, ProgressRecorder $progress, LearningEventRecorder $events, AdaptiveEngine $adaptive): void
    {
        $submission = WritingSubmission::with('learnerProfile')->findOrFail($this->submissionId);
        if ($submission->status === 'evaluated') {
            return;
        }
        $evaluation = $evaluator->evaluate($submission);
        $profile = $submission->learnerProfile;
        $progress->increment($profile, Skill::where('slug', Skill::WRITING)->value('id'), ['exercises_completed' => 1]);
        $events->record($profile, 'writing.evaluated', $submission, Skill::where('slug', Skill::WRITING)->value('id'), $evaluation->overall_score);
        $adaptive->refresh($profile);
    }

    public function failed(Throwable $e): void
    {
        $submission = WritingSubmission::with('learnerProfile.user')->find($this->submissionId);
        if ($submission && $submission->status !== 'failed') {
            $submission->update(['status' => 'failed']);
            if (! $submission->assessment_attempt_id) { // placement writing is charged under placement_productive
                app(QuotaGuard::class)->release($submission->learnerProfile->user, 'writing_evaluations');
            }
        }
    }
}
