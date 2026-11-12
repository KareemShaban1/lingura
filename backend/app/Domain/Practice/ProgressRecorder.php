<?php

namespace App\Domain\Practice;

use App\Models\LearnerProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        $set = implode(', ', array_map(fn ($c) => "{$c} = user_progress.{$c} + EXCLUDED.{$c}", self::COUNTERS));

        DB::statement(
            'INSERT INTO user_progress (learner_profile_id, date, skill_id, '.implode(', ', self::COUNTERS).', created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, now(), now())
             ON CONFLICT (learner_profile_id, date, skill_id) DO UPDATE SET '.$set.', updated_at = now()',
            [$profile->id, $date, $skillId, ...array_values($values)],
        );
    }
}
