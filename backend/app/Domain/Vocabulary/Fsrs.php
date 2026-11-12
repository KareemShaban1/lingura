<?php

namespace App\Domain\Vocabulary;

/**
 * FSRS-4.5 spaced-repetition scheduler with the published default parameters.
 * Pure and deterministic; every review is logged so parameters can be re-fitted per platform later.
 *
 * Ratings: 1 Again · 2 Hard · 3 Good · 4 Easy. Target retention 0.9 ⇒ interval ≈ stability (days).
 */
final class Fsrs
{
    private const W = [0.4872, 1.4003, 3.7145, 13.8206, 5.1618, 1.2298, 0.8975, 0.031, 1.6474, 0.1367, 1.0461, 2.1072, 0.0793, 0.3246, 1.587, 0.2272, 2.8755];

    private const DECAY = -0.5;

    private const FACTOR = 19 / 81;

    public function __construct(private readonly float $retention = 0.9, private readonly int $maxInterval = 365) {}

    public function retrievability(float $elapsedDays, float $stability): float
    {
        return (1 + self::FACTOR * $elapsedDays / $stability) ** self::DECAY;
    }

    public function intervalDays(float $stability): int
    {
        $days = $stability / self::FACTOR * ($this->retention ** (1 / self::DECAY) - 1);

        return (int) max(1, min($this->maxInterval, round($days)));
    }

    /**
     * @return array{stability: float, difficulty: float, interval_days: int, state: string, retrievability: ?float}
     */
    public function review(?float $stability, ?float $difficulty, float $elapsedDays, int $rating): array
    {
        $w = self::W;
        if ($stability === null || $difficulty === null) {
            $s = $w[$rating - 1];
            $d = $this->clampD($this->initialDifficulty($rating));
            $r = null;
        } else {
            $r = $this->retrievability($elapsedDays, $stability);
            $d = $this->clampD($w[7] * $this->initialDifficulty(4) + (1 - $w[7]) * ($difficulty - $w[6] * ($rating - 3)));
            $s = $rating === 1
                ? $w[11] * ($difficulty ** -$w[12]) * ((($stability + 1) ** $w[13]) - 1) * exp($w[14] * (1 - $r))
                : $stability * (1 + exp($w[8]) * (11 - $difficulty) * ($stability ** -$w[9]) * (exp($w[10] * (1 - $r)) - 1)
                    * ($rating === 2 ? $w[15] : 1) * ($rating === 4 ? $w[16] : 1));
        }
        $s = max(0.1, $s);

        return [
            'stability' => round($s, 4),
            'difficulty' => round($d, 4),
            // A failed card comes back the same day; otherwise use the retention-based interval.
            'interval_days' => $rating === 1 ? 0 : $this->intervalDays($s),
            'state' => $rating === 1 ? ($stability === null ? 'learning' : 'relearning') : 'review',
            'retrievability' => $r === null ? null : round($r, 4),
        ];
    }

    private function initialDifficulty(int $rating): float
    {
        return self::W[4] - (self::W[5] * ($rating - 3));
    }

    private function clampD(float $d): float
    {
        return max(1.0, min(10.0, $d));
    }
}
