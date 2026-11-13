<?php

namespace Database\Seeders;

use App\Domain\Assessment\PlacementService;
use App\Domain\Identity\AccountService;
use App\Domain\Learning\LearnerProfileService;
use App\Domain\Practice\ExerciseService;
use App\Domain\Vocabulary\VocabularyService;
use App\Models\AssessmentItem;
use App\Models\Exercise;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\VocabularyWord;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo learner for trying the app locally (`php artisan db:seed --class=DemoSeeder`).
 * Everything is created through the normal services — the placement is actually taken, exercises are
 * actually answered — so the dashboard, plan, progress and rewards show what the app really computes.
 * Refuses to run outside the local environment.
 */
class DemoSeeder extends Seeder
{
    public function run(
        AccountService $accounts,
        LearnerProfileService $profiles,
        PlacementService $placement,
        ExerciseService $exercises,
        VocabularyService $vocabulary,
    ): void {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoSeeder only runs in the local environment.');
        }
        if (User::where('email', 'demo@linguaforge.test')->exists()) {
            $this->command?->info('Demo learner already exists.');

            return;
        }

        $user = $accounts->register([
            'name' => 'Demo Learner', 'email' => 'demo@linguaforge.test', 'password' => 'password',
            'native_language' => 'ar', 'interface_language' => 'en', 'timezone' => 'UTC',
        ]);
        $profile = $profiles->create($user, [
            'target_language' => 'es', 'purpose' => 'travel', 'daily_minutes' => 30,
            'self_reported_level' => 'A2', 'focus_skills' => ['speaking', 'vocabulary'],
        ]);

        // Take the placement: answers items up to B1 correctly, harder ones wrong; productive sections skipped.
        $attempt = $placement->start($profile);
        for ($i = 0; $i < 80; $i++) {
            $next = $placement->next($attempt->refresh());
            if ($next === null) {
                break;
            }
            if ($next instanceof ProductionTask) {
                $placement->skipSection($attempt, $next->mode === 'spoken' ? 'speaking' : 'writing');

                continue;
            }
            /** @var AssessmentItem $next */
            $next->loadMissing(['options', 'level']);
            $option = $next->level->ordinal <= 3 ? $next->options->firstWhere('is_correct', true) : $next->options->firstWhere('is_correct', false);
            $placement->answer($attempt, $next->public_id, $option->id, 9000);
        }
        if ($attempt->refresh()->status !== 'completed') {
            $placement->complete($attempt);
        }

        // A few exercises answered (mostly correctly) and some flashcards reviewed.
        foreach (['es-a1-gustar-mcq', 'es-a1-order', 'es-a1-vocab-mcq'] as $n => $key) {
            $exercise = Exercise::where('key', $key)->with('questions.options')->first();
            if (! $exercise) {
                continue;
            }
            $run = $exercises->start($profile, $exercise);
            foreach ($exercise->questions as $q => $question) {
                $right = $q !== $n; // one mistake per exercise, in a different place each time
                $payload = $exercise->type === 'ordering'
                    ? ['order' => $question->options->sortBy($right ? 'correct_position' : 'position')->pluck('id')->all()]
                    : ['option_id' => $question->options->firstWhere('is_correct', $right)->id];
                $exercises->answer($run, $question->id, $payload);
            }
            $exercises->finish($run->fresh());
        }
        foreach (VocabularyWord::whereHas('language', fn ($q) => $q->where('code', 'es'))->orderBy('frequency_rank')->limit(10)->get() as $i => $word) {
            $card = $vocabulary->add($profile, $word, 'demo');
            if ($i < 5) {
                $vocabulary->review($card, $i === 0 ? 1 : 3, 4000);
            }
        }

        $this->command?->info('Demo learner: demo@linguaforge.test / password (Spanish, placement taken).');
    }
}
