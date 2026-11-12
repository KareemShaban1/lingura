<?php

namespace Tests\Feature;

use App\Domain\Gamification\GamificationService;
use App\Domain\Shared\LearningEventRecorder;
use App\Models\Exercise;
use App\Models\LearnerProfile;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\UserAchievement;
use App\Models\UserVocabulary;
use App\Models\VocabularyWord;
use App\Models\XpEntry;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GamificationTest extends TestCase
{
    private function finishExercise(LearnerProfile $profile, float $score, string $key = 'es-a1-gustar-mcq'): void
    {
        $exercise = Exercise::where('key', $key)->firstOrFail();
        app(LearningEventRecorder::class)->record($profile, 'exercise.completed', $exercise, $exercise->skill_id, $score, ['exercise' => $key]);
    }

    public function test_xp_comes_from_verified_outcomes_once_per_outcome(): void
    {
        $profile = $this->makeLearner('es');
        $this->actingAsUser($profile->user);

        $this->finishExercise($profile, 0.8);
        $this->finishExercise($profile, 1.0); // same exercise, same day: no farming
        $this->finishExercise($profile, 0.0, 'es-a1-order'); // nothing correct: no XP
        $this->assertSame(8, (int) XpEntry::where('user_id', $profile->user_id)->sum('amount'));

        $card = UserVocabulary::create(['learner_profile_id' => $profile->id, 'vocabulary_word_id' => VocabularyWord::first()->id, 'due_at' => now(), 'source' => 'manual']);
        $this->postJson("/api/v1/user-vocabulary/{$card->id}/reviews", ['rating' => 1])->assertOk(); // forgotten: no XP
        $this->postJson("/api/v1/user-vocabulary/{$card->id}/reviews", ['rating' => 3])->assertOk();
        $this->postJson("/api/v1/user-vocabulary/{$card->id}/reviews", ['rating' => 4])->assertOk(); // same card, same day

        $summary = $this->getJson('/api/v1/me/gamification')->assertOk()->json('data');
        $this->assertSame(9, $summary['xp_total']);
        $this->assertSame(9, $summary['xp_today']);
        $this->assertSame(1, $summary['streak']['current_days']);
        $this->assertTrue($summary['streak']['active_today']);
        $this->assertFalse($summary['leaderboard_opt_in']);
    }

    public function test_streak_freezes_cover_missed_days(): void
    {
        $profile = $this->makeLearner('es');
        $game = app(GamificationService::class);
        $user = $profile->user;
        $start = Carbon::parse('2026-11-02 10:00:00');

        foreach (range(0, 6) as $d) { // 7 consecutive days → one freeze
            Carbon::setTestNow($start->copy()->addDays($d));
            $this->finishExercise($profile, 1.0);
        }
        $this->assertSame(['current_days' => 7, 'freezes_available' => 1], array_intersect_key($game->streak($user), ['current_days' => 0, 'freezes_available' => 0]));

        Carbon::setTestNow($start->copy()->addDays(8)); // missed day 7, covered by the freeze
        $this->assertTrue($game->streak($user)['at_risk']);
        $this->finishExercise($profile, 1.0);
        $this->assertSame(8, $game->streak($user)['current_days']);
        $this->assertSame(0, $game->streak($user)['freezes_available']);
        $this->assertDatabaseHas('streak_days', ['user_id' => $user->id, 'date' => $start->copy()->addDays(7)->toDateString(), 'kind' => 'frozen']);

        Carbon::setTestNow($start->copy()->addDays(11)); // two missed days, no freezes left
        $this->assertSame(0, $game->streak($user)['current_days']);
        $this->finishExercise($profile, 1.0);
        $this->assertSame(1, $game->streak($user)['current_days']);
        $this->assertSame(8, $game->streak($user)['longest_days']);
        Carbon::setTestNow();
    }

    public function test_achievements_challenges_and_opt_in_leaderboard(): void
    {
        $profile = $this->makeLearner('es');
        $user = $this->actingAsUser($profile->user);
        $lesson = Lesson::firstOrFail();
        LessonProgress::create(['learner_profile_id' => $profile->id, 'lesson_id' => $lesson->id, 'status' => 'completed', 'started_at' => now(), 'completed_at' => now()]);
        app(LearningEventRecorder::class)->record($profile, 'lesson.completed', $lesson, $lesson->skill_id, 1.0);

        $this->assertTrue(UserAchievement::where('user_id', $user->id)->exists(), 'first_lesson unlocked');
        $achievements = collect($this->getJson('/api/v1/me/achievements')->assertOk()->json('data'));
        $this->assertNotNull($achievements->firstWhere('slug', 'first_lesson')['unlocked_at']);
        $this->assertNull($achievements->firstWhere('slug', 'placement_done')['unlocked_at']);
        $this->assertSame(30, (int) XpEntry::where('user_id', $user->id)->sum('amount'), '20 lesson + 10 achievement reward');

        // Completing the same lesson again earns nothing.
        app(LearningEventRecorder::class)->record($profile, 'lesson.completed', $lesson, $lesson->skill_id, 1.0);
        $this->assertSame(30, (int) XpEntry::where('user_id', $user->id)->sum('amount'));

        $challenges = $this->getJson('/api/v1/me/challenges')->assertOk()->json('data');
        $this->assertCount(3, $challenges);

        // Not opted in → not on the board.
        $this->assertSame([], $this->getJson('/api/v1/leaderboards/weekly')->assertOk()->json('data.entries'));

        $other = $this->makeLearner('es', user: $this->makeUser(['name' => 'Omar Said']));
        $this->finishExercise($other, 1.0);
        $this->actingAsUser($other->user);
        $this->patchJson('/api/v1/me/leaderboard', ['opt_in' => true])->assertOk();
        $this->actingAsUser($user);
        $this->patchJson('/api/v1/me/leaderboard', ['opt_in' => true, 'alias' => 'Polyglot_N'])->assertOk()->assertJsonPath('data.leaderboard_opt_in', true);
        $this->patchJson('/api/v1/me/leaderboard', ['opt_in' => true, 'alias' => '<script>'])->assertStatus(422);

        $board = $this->getJson('/api/v1/leaderboards/weekly')->assertOk()->json('data');
        $this->assertSame(['Polyglot_N', 'Omar S.'], array_column($board['entries'], 'name'));
        $this->assertSame(1, $board['you']['rank']);
    }
}
