<?php

namespace App\Domain\Gamification;

use App\Models\Achievement;
use App\Models\AiConversation;
use App\Models\AssessmentAttempt;
use App\Models\Challenge;
use App\Models\ChallengeProgress;
use App\Models\ExerciseAttempt;
use App\Models\LearningEvent;
use App\Models\LessonProgress;
use App\Models\Streak;
use App\Models\StreakDay;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserVocabulary;
use App\Models\WritingSubmission;
use App\Models\XpEntry;
use App\Notifications\AccountNotice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns learning events into XP, streaks, achievements and challenge progress. Driven only by
 * evidence already recorded by the learning modules; it never changes learning data itself.
 */
class GamificationService
{
    public function handle(LearningEvent $event): void
    {
        $user = User::with('profile')->find($event->user_id);
        if (! $user) {
            return;
        }
        // Time-tracked study (capped at real elapsed time) keeps the streak alive but earns no XP by itself.
        if ($event->event_type === 'session.completed' && (float) $event->value >= config('gamification.streak.min_session_minutes')) {
            $this->touchStreak($user);
            $this->checkAchievements($user);

            return;
        }
        $award = $this->xpFor($event, $user);
        if ($award === null) {
            return;
        }
        [$source, $amount, $key] = $award;
        if (! $this->award($user, $event->learner_profile_id, $source, $amount, $key)) {
            return; // duplicate outcome: no XP, no streak credit
        }
        $this->touchStreak($user);
        $this->checkAchievements($user);
        $this->checkChallenges($user);
    }

    /** @return array{0: string, 1: int, 2: string}|null */
    private function xpFor(LearningEvent $e, User $user): ?array
    {
        $c = config('gamification.xp');
        $day = $this->today($user)->toDateString();

        return match ($e->event_type) {
            'exercise.completed' => ($xp = (int) round($c['exercise_max'] * (float) $e->value)) > 0
                ? ['exercise', $xp, "exercise:{$e->learner_profile_id}:".($e->metadata['exercise'] ?? $e->subject_id).":{$day}"] : null,
            'lesson.completed' => ['lesson', $c['lesson'], "lesson:{$e->learner_profile_id}:{$e->subject_id}"],
            'vocabulary.reviewed' => (int) $e->value >= 2 ? ['vocabulary', $c['review'], "review:{$e->subject_id}:{$day}"] : null,
            'tutor.completed' => (int) $e->value >= $c['conversation_min_turns'] ? ['tutor', $c['conversation'], "tutor:{$e->subject_id}"] : null,
            'writing.evaluated' => ($e->metadata['placement'] ?? false) ? null
                : ['writing', $c['writing_base'] + 2 * (int) round((float) $e->value), "writing:{$e->subject_id}"],
            'assessment.completed' => ['assessment', $c['assessment'], "assessment:{$e->subject_id}"],
            default => null,
        };
    }

    /** Idempotent: false when this outcome was already rewarded. */
    public function award(User $user, ?int $profileId, string $source, int $amount, string $key): bool
    {
        try {
            DB::transaction(fn () => XpEntry::create([
                'user_id' => $user->id, 'learner_profile_id' => $profileId, 'source' => $source, 'amount' => $amount,
                'dedupe_key' => $key, 'earned_on' => $this->today($user)->toDateString(), 'created_at' => now(),
            ]));

            return true;
        } catch (UniqueConstraintViolationException) { // dedupe_key already rewarded (savepoint rolled back)
            return false;
        }
    }

    /** A day counts when at least one verified outcome earned XP; missed days consume freezes if available. */
    private function touchStreak(User $user): void
    {
        $today = $this->today($user)->startOfDay();
        $streak = Streak::firstOrCreate(['user_id' => $user->id]);
        $streak = Streak::whereKey($streak->id)->lockForUpdate()->first();
        $last = $streak->last_active_on ? Carbon::parse($streak->last_active_on->toDateString(), $today->getTimezone()) : null;

        if ($last && $last->equalTo($today)) {
            return;
        }
        $cfg = config('gamification.streak');
        $gap = $last ? (int) $last->diffInDays($today) - 1 : null; // missed days between last activity and today
        $current = match (true) {
            $gap === null => 1,
            $gap === 0 => $streak->current_days + 1,
            $gap <= $streak->freezes_available => $streak->current_days + 1,
            default => 1,
        };
        $freezes = $streak->freezes_available;
        if ($gap !== null && $gap > 0 && $gap <= $freezes) {
            for ($i = 1; $i <= $gap; $i++) {
                StreakDay::firstOrCreate(['user_id' => $user->id, 'date' => $last->copy()->addDays($i)->toDateString()], ['kind' => 'frozen']);
            }
            $freezes -= $gap;
        }
        if ($current % $cfg['freeze_every_days'] === 0) {
            $freezes = min($cfg['max_freezes'], $freezes + 1);
        }
        StreakDay::firstOrCreate(['user_id' => $user->id, 'date' => $today->toDateString()], ['kind' => 'active']);
        $streak->update([
            'current_days' => $current, 'longest_days' => max($streak->longest_days, $current),
            'last_active_on' => $today->toDateString(), 'freezes_available' => $freezes,
        ]);
    }

    /** @return array{current_days: int, longest_days: int, freezes_available: int, active_today: bool, at_risk: bool} */
    public function streak(User $user): array
    {
        $streak = Streak::where('user_id', $user->id)->first();
        $today = $this->today($user)->startOfDay();
        if (! $streak || ! $streak->last_active_on) {
            return ['current_days' => 0, 'longest_days' => 0, 'freezes_available' => 0, 'active_today' => false, 'at_risk' => false];
        }
        $last = Carbon::parse($streak->last_active_on->toDateString(), $today->getTimezone());
        $missed = max(0, (int) $last->diffInDays($today) - 1);
        $alive = $last->equalTo($today) || $missed <= $streak->freezes_available;

        return [
            'current_days' => $alive ? $streak->current_days : 0,
            'longest_days' => $streak->longest_days,
            'freezes_available' => $streak->freezes_available,
            'active_today' => $last->equalTo($today),
            'at_risk' => $alive && ! $last->equalTo($today) && $missed >= $streak->freezes_available,
        ];
    }

    /** @return array<string, int> */
    public function metrics(User $user): array
    {
        $profileIds = $user->learnerProfiles()->pluck('id');

        return [
            'lessons_completed' => LessonProgress::whereIn('learner_profile_id', $profileIds)->where('status', 'completed')->count(),
            'exercises_completed' => ExerciseAttempt::whereIn('learner_profile_id', $profileIds)->where('status', 'completed')->count(),
            'words_learned' => UserVocabulary::whereIn('learner_profile_id', $profileIds)->where('state', 'review')->count(),
            'streak_days' => $this->streak($user)['current_days'],
            'conversations' => AiConversation::whereIn('learner_profile_id', $profileIds)->whereNotNull('ended_at')
                ->where('learner_turns', '>=', config('gamification.xp.conversation_min_turns'))->count(),
            'writings' => WritingSubmission::whereIn('learner_profile_id', $profileIds)->whereNull('assessment_attempt_id')->where('status', 'evaluated')->count(),
            'assessments' => AssessmentAttempt::whereIn('learner_profile_id', $profileIds)->where('status', 'completed')->count(),
            'xp_total' => (int) XpEntry::where('user_id', $user->id)->sum('amount'),
        ];
    }

    private function checkAchievements(User $user): void
    {
        $unlocked = UserAchievement::where('user_id', $user->id)->pluck('achievement_id')->all();
        $pending = Achievement::where('is_active', true)->whereNotIn('id', $unlocked)->with('translations')->get();
        if ($pending->isEmpty()) {
            return;
        }
        $metrics = $this->metrics($user);
        foreach ($pending as $achievement) {
            if (($metrics[$achievement->metric] ?? 0) < $achievement->threshold) {
                continue;
            }
            $row = UserAchievement::firstOrCreate(['user_id' => $user->id, 'achievement_id' => $achievement->id], ['unlocked_at' => now()]);
            if (! $row->wasRecentlyCreated) {
                continue;
            }
            if ($achievement->xp_reward > 0) {
                $this->award($user, null, 'achievement', $achievement->xp_reward, "achievement:{$achievement->id}:{$user->id}");
            }
            $user->notify(new AccountNotice('achievement_unlocked', ['achievement' => $achievement->slug]));
        }
    }

    private function checkChallenges(User $user): void
    {
        foreach ($this->currentChallenges() as $challenge) {
            $progress = $this->challengeProgress($user, $challenge);
            if ($progress < $challenge->target) {
                continue;
            }
            $row = ChallengeProgress::firstOrCreate(['challenge_id' => $challenge->id, 'user_id' => $user->id]);
            if ($row->completed_at === null) {
                $row->update(['progress' => $progress, 'completed_at' => now()]);
                $this->award($user, null, 'challenge', $challenge->xp_reward, "challenge:{$challenge->id}:{$user->id}");
            }
        }
    }

    public function challengeProgress(User $user, Challenge $challenge): int
    {
        $range = [$challenge->starts_on->toDateString(), $challenge->ends_on->toDateString()];
        $xp = XpEntry::where('user_id', $user->id)->whereBetween('earned_on', $range);

        return (int) match ($challenge->metric) {
            'xp' => (clone $xp)->whereNotIn('source', ['achievement', 'challenge'])->sum('amount'),
            'exercises' => (clone $xp)->where('source', 'exercise')->count(),
            'reviews' => (clone $xp)->where('source', 'vocabulary')->count(),
            'conversations' => (clone $xp)->where('source', 'tutor')->count(),
            'minutes' => round((float) LearningEvent::where('user_id', $user->id)->where('event_type', 'session.completed')
                ->whereBetween('occurred_at', [$challenge->starts_on->copy()->startOfDay(), $challenge->ends_on->copy()->endOfDay()])->sum('value')),
            default => 0,
        };
    }

    /** @return Collection<int, Challenge> */
    public function currentChallenges(): Collection
    {
        $monday = Carbon::now('UTC')->startOfWeek(Carbon::MONDAY);
        $existing = Challenge::where('starts_on', $monday->toDateString())->orderBy('id')->get();

        return $existing->isNotEmpty() ? $existing : $this->generateWeek($monday);
    }

    /** @return Collection<int, Challenge> */
    public function generateWeek(Carbon $monday): Collection
    {
        $pool = config('gamification.challenge_pool');
        $offset = (int) $monday->isoWeek() % count($pool);
        $picked = collect(range(0, 2))->map(fn ($i) => $pool[($offset + $i * 2) % count($pool)])->unique('slug');

        return $picked->map(fn ($c) => Challenge::firstOrCreate(
            ['slug' => $c['slug'], 'starts_on' => $monday->toDateString()],
            ['metric' => $c['metric'], 'target' => $c['target'], 'xp_reward' => $c['xp_reward'], 'ends_on' => $monday->copy()->addDays(6)->toDateString()],
        ))->values();
    }

    public function today(User $user): Carbon
    {
        return Carbon::now($user->profile?->timezone ?: 'UTC');
    }
}
