<?php

namespace App\Domain\Personalization;

use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\LearnerProfile;
use App\Models\PersonalizationDecision;
use Illuminate\Support\Facades\DB;

/**
 * Thompson-sampling bandit over exercise formats (`thompson_beta_v1`).
 *
 * Scope is deliberately narrow: it only chooses *which format* to use among exercises that already passed
 * the rule-based filters (skill allocation, level, recency). Plans, levels and reasons stay rule-based.
 *
 * Reward ("desirable difficulty"): a finished exercise scoring within the target band (0.6–0.9) = 1,
 * finished outside the band = 0.5, skipped or left unfinished for a week = 0. Each arm is Beta(α, β) with
 * fractional updates, starting from a population prior (same language, context and arm) shrunk to at most
 * `prior_weight` pseudo-observations. Every decision is logged with its propensity for off-policy evaluation.
 */
class FormatBandit
{
    public const POLICY = 'thompson_beta_v1';

    private int $decisions = 0;

    /**
     * @param  array<string, Exercise>  $candidates  arm (format) => best candidate exercise for that arm
     */
    public function choose(LearnerProfile $profile, string $context, array $candidates): Exercise
    {
        $arms = array_keys($candidates);
        if (count($arms) < 2 || ! config('learning.personalization.enabled')) {
            return reset($candidates);
        }
        $posteriors = $this->posteriors($profile, $context, $arms);

        // Deterministic for a given learner, day and decision index: regenerating a plan reproduces it.
        $rng = new SeededRandom($profile->id.'|'.$context.'|'.now()->toDateString().'|'.$this->decisions++);
        $samples = array_map(fn ($p) => $rng->beta($p['alpha'], $p['beta']), $posteriors);
        $chosen = array_keys($samples, max($samples))[0];
        $propensities = $this->propensities($posteriors, new SeededRandom('propensity|'.$profile->id.'|'.$context));

        PersonalizationDecision::create([
            'learner_profile_id' => $profile->id,
            'policy' => self::POLICY,
            'context' => $context,
            'arms' => array_map(fn ($arm) => [
                'arm' => $arm, 'alpha' => round($posteriors[$arm]['alpha'], 3), 'beta' => round($posteriors[$arm]['beta'], 3),
                'sample' => round($samples[$arm], 4), 'propensity' => $propensities[$arm],
            ], $arms),
            'chosen_arm' => $chosen,
            'propensity' => max(0.0001, $propensities[$chosen]),
            'exercise_id' => $candidates[$chosen]->id,
            'created_at' => now(),
        ]);

        return $candidates[$chosen];
    }

    /** Records the outcome of a finished attempt against the open decision that assigned this exercise. */
    public function observeAttempt(ExerciseAttempt $attempt): void
    {
        $c = config('learning.personalization');
        $score = (float) $attempt->score;
        $reward = $score >= $c['target_low'] && $score <= $c['target_high'] ? 1.0 : 0.5;
        $this->resolve($attempt->learner_profile_id, $attempt->exercise_id, $reward);
    }

    public function observeSkip(int $profileId, ?int $exerciseId): void
    {
        if ($exerciseId) {
            $this->resolve($profileId, $exerciseId, 0.0);
        }
    }

    /** @return array<string, array{alpha: float, beta: float}> */
    public function posteriors(LearnerProfile $profile, string $context, array $arms): array
    {
        $c = config('learning.personalization');
        $t = 'personalization_decisions';
        $stats = fn ($query) => $query->where("{$t}.context", $context)->whereIn("{$t}.chosen_arm", $arms)
            ->where(fn ($q) => $q->whereNotNull("{$t}.reward")->orWhere("{$t}.created_at", '<', now()->subDays($c['unresolved_after_days'])))
            ->groupBy("{$t}.chosen_arm")
            ->selectRaw("{$t}.chosen_arm, count(*) as n, coalesce(sum({$t}.reward), 0) as s")
            ->get()->keyBy('chosen_arm');

        $own = $stats(PersonalizationDecision::where('learner_profile_id', $profile->id));
        $population = $stats(PersonalizationDecision::query()
            ->join('learner_profiles', 'learner_profiles.id', '=', 'personalization_decisions.learner_profile_id')
            ->where('learner_profiles.target_language_id', $profile->target_language_id)
            ->where('personalization_decisions.learner_profile_id', '!=', $profile->id));

        $out = [];
        foreach ($arms as $arm) {
            $pop = $population->get($arm);
            $weight = $pop ? min($c['prior_weight'], (float) $pop->n) : 0.0;
            $mean = $pop && $pop->n > 0 ? (float) $pop->s / $pop->n : 0.5;
            $mine = $own->get($arm);
            $out[$arm] = [
                'alpha' => 1 + $weight * $mean + (float) ($mine->s ?? 0),
                'beta' => 1 + $weight * (1 - $mean) + (float) ($mine?->n ?? 0) - (float) ($mine->s ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Monte-Carlo estimate of P(arm is chosen) under the posterior — logged so other policies can be
     * evaluated offline with inverse-propensity weighting.
     *
     * @return array<string, float>
     */
    private function propensities(array $posteriors, SeededRandom $rng, int $draws = 400): array
    {
        $wins = array_fill_keys(array_keys($posteriors), 0);
        for ($i = 0; $i < $draws; $i++) {
            $s = array_map(fn ($p) => $rng->beta($p['alpha'], $p['beta']), $posteriors);
            $wins[array_keys($s, max($s))[0]]++;
        }

        return array_map(fn ($w) => round($w / $draws, 4), $wins);
    }

    private function resolve(int $profileId, int $exerciseId, float $reward): void
    {
        DB::table('personalization_decisions')
            ->where('learner_profile_id', $profileId)->where('exercise_id', $exerciseId)->whereNull('reward')
            ->where('created_at', '>=', now()->subDays(config('learning.personalization.unresolved_after_days')))
            ->update(['reward' => $reward, 'rewarded_at' => now()]);
    }
}
