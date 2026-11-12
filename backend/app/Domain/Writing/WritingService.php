<?php

namespace App\Domain\Writing;

use App\Domain\Shared\DomainError;
use App\Jobs\EvaluateWriting;
use App\Models\LearnerProfile;
use App\Models\ProductionTask;
use App\Models\WritingSubmission;

class WritingService
{
    public function submit(LearnerProfile $profile, ProductionTask $task, string $text): WritingSubmission
    {
        if ($task->language_id !== $profile->target_language_id || $task->mode !== 'written') {
            throw new DomainError('This writing task is not available for your language.', 'invalid_task', 422);
        }
        $words = TextStats::of($text)['words'];
        if ($words < max(3, (int) floor($task->min_words / 3))) {
            throw new DomainError('Write a little more before submitting.', 'text_too_short', 422);
        }
        if ($words > $task->max_words * 2) {
            throw new DomainError('This text is much longer than the task asks for.', 'text_too_long', 422);
        }

        $submission = WritingSubmission::create([
            'learner_profile_id' => $profile->id,
            'production_task_id' => $task->id,
            'text' => $text,
            'word_count' => $words,
            'status' => 'pending',
        ]);
        EvaluateWriting::dispatch($submission->id)->afterCommit();

        return $submission;
    }
}
