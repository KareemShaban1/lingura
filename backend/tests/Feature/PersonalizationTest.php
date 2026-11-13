<?php

namespace Tests\Feature;

use App\Domain\Personalization\FormatBandit;
use App\Models\Exercise;
use App\Models\ExerciseOption;
use App\Models\LearnerProfile;
use App\Models\PersonalizationDecision;
use Tests\TestCase;

class PersonalizationTest extends TestCase
{
    /** @return array<string, Exercise> */
    private function candidates(): array
    {
        return ['cloze' => Exercise::where('key', 'es-a1-ar-cloze')->firstOrFail(), 'mcq' => Exercise::where('key', 'es-a1-gustar-mcq')->firstOrFail()];
    }

    private function history(LearnerProfile $profile, string $arm, int $n, float $reward): void
    {
        for ($i = 0; $i < $n; $i++) {
            PersonalizationDecision::create([
                'learner_profile_id' => $profile->id, 'policy' => FormatBandit::POLICY, 'context' => 'grammar', 'arms' => [],
                'chosen_arm' => $arm, 'propensity' => 0.5, 'reward' => $reward, 'rewarded_at' => now(), 'created_at' => now()->subDays(2),
            ]);
        }
    }

    public function test_posteriors_start_flat_and_learn_from_rewards_and_the_population(): void
    {
        $profile = $this->makeLearner('es');
        $bandit = app(FormatBandit::class);
        $this->assertSame(['alpha' => 1.0, 'beta' => 1.0], $bandit->posteriors($profile, 'grammar', ['cloze'])['cloze']);

        $this->history($profile, 'cloze', 4, 1.0);
        $this->history($profile, 'mcq', 4, 0.0);
        $p = $bandit->posteriors($profile, 'grammar', ['cloze', 'mcq']);
        $this->assertSame(['alpha' => 5.0, 'beta' => 1.0], $p['cloze']);
        $this->assertSame(['alpha' => 1.0, 'beta' => 5.0], $p['mcq']);

        // A new learner borrows (at most 5 pseudo-observations of) what worked for others.
        $newcomer = $this->makeLearner('es');
        $prior = $bandit->posteriors($newcomer, 'grammar', ['cloze', 'mcq']);
        $this->assertGreaterThan($prior['mcq']['alpha'], $prior['cloze']['alpha']);
        $this->assertEqualsWithDelta(6.0, $prior['cloze']['alpha'] + $prior['cloze']['beta'], 0.001, '1 + 1 + 4 borrowed pseudo-observations (capped at 5)');
    }

    public function test_choices_favour_the_better_format_and_are_logged_with_propensities(): void
    {
        $profile = $this->makeLearner('es');
        $this->history($profile, 'cloze', 12, 1.0);
        $this->history($profile, 'mcq', 12, 0.2);

        $bandit = app(FormatBandit::class);
        $picks = collect(range(1, 30))->map(fn () => $bandit->choose($profile, 'grammar', $this->candidates())->type);
        $this->assertGreaterThan(25, $picks->filter(fn ($t) => $t === 'cloze')->count(), 'exploits the format that works');

        $decision = PersonalizationDecision::whereNull('reward')->latest('id')->firstOrFail();
        $this->assertSame(FormatBandit::POLICY, $decision->policy);
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($decision->arms, 'propensity')), 0.01);
        $this->assertGreaterThan(0.8, collect($decision->arms)->firstWhere('arm', 'cloze')['propensity']);

        // Same state, same day, same decision index → same choice (plans stay reproducible).
        $a = (new FormatBandit)->choose($profile, 'grammar', $this->candidates())->type;
        $b = (new FormatBandit)->choose($profile, 'grammar', $this->candidates())->type;
        $this->assertSame($a, $b);
    }

    public function test_rewards_follow_desirable_difficulty_and_the_feature_can_be_switched_off(): void
    {
        $profile = $this->makeLearner('es');
        $this->actingAsUser($profile->user);
        $exercise = (new FormatBandit)->choose($profile, 'grammar', ['mcq' => $this->candidates()['mcq'], 'cloze' => $this->candidates()['cloze']]);

        $attempt = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/exercises/{$exercise->public_id}/attempts")->assertSuccessful()->json('data');
        foreach ($attempt['exercise']['questions'] as $i => $q) {
            // Get all but one right → score in the 0.6–0.9 target band.
            $body = $exercise->type === 'cloze'
                ? ['question_id' => $q['id'], 'text' => $i === 0 ? 'wrong' : $exercise->questions()->find($q['id'])->acceptedAnswers()->value('answer')]
                : ['question_id' => $q['id'], 'option_id' => ExerciseOption::where('exercise_question_id', $q['id'])->where('is_correct', $i !== 0)->value('id')];
            $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/answers", $body)->assertOk();
        }
        $this->postJson("/api/v1/exercise-attempts/{$attempt['id']}/finish")->assertOk();
        $this->assertSame(1.0, PersonalizationDecision::where('exercise_id', $exercise->id)->value('reward'));

        config(['learning.personalization.enabled' => false]);
        $count = PersonalizationDecision::count();
        (new FormatBandit)->choose($profile, 'grammar', $this->candidates());
        $this->assertSame($count, PersonalizationDecision::count(), 'no decisions when disabled');
    }
}
