<?php

namespace App\Domain\Shared;

use App\Models\LearnerProfile;
use App\Models\LearningEvent;
use Illuminate\Database\Eloquent\Model;

/** Append-only learning analytics events. */
class LearningEventRecorder
{
    public function record(
        LearnerProfile $profile,
        string $type,
        ?Model $subject = null,
        ?int $skillId = null,
        ?float $value = null,
        array $metadata = [],
    ): LearningEvent {
        return LearningEvent::create([
            'user_id' => $profile->user_id,
            'learner_profile_id' => $profile->id,
            'event_type' => $type,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'skill_id' => $skillId,
            'value' => $value,
            'metadata' => $metadata ?: null,
            'occurred_at' => now(),
        ]);
    }
}
