<?php

namespace App\Domain\Shared;

use App\Domain\Gamification\GamificationService;
use App\Models\LearnerProfile;
use App\Models\LearningEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

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
        $event = LearningEvent::create([
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

        // Rewards are a side effect: a failure there must never undo the learning action (savepoint + report).
        try {
            DB::transaction(fn () => app(GamificationService::class)->handle($event));
        } catch (Throwable $e) {
            report($e);
        }

        return $event;
    }
}
