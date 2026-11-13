<?php

namespace App\Domain\Practice;

use App\Models\LearnerProfile;
use App\Support\Sql;
use Illuminate\Support\Carbon;

/** Daily per-skill counters on the learner's local calendar date (single atomic upsert). */
class ProgressRecorder
{
    private const COUNTERS = ['questions_answered', 'questions_correct', 'exercises_completed', 'words_reviewed'];

    /** @param array<string, int> $increments counter => amount */
    public function increment(LearnerProfile $profile, int $skillId, array $increments): void
    {
        $values = [];
        foreach (self::COUNTERS as $counter) {
            $values[$counter] = (int) ($increments[$counter] ?? 0);
        }
        $date = Carbon::now($profile->user->profile?->timezone ?? 'UTC')->toDateString();

        Sql::upsertIncrement('user_progress', ['learner_profile_id' => $profile->id, 'date' => $date, 'skill_id' => $skillId], $values);
    }
}
