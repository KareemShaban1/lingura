<?php

namespace App\Domain\Assessment\Engine;

/**
 * Rasch model with a fixed guessing floor (c = 1/k) and EAP ability estimation.
 * Pure and deterministic — see docs/09-assessment-methodology.md.
 */
final class IrtModel
{
    /** @var list<float> */
    private array $grid = [];

    public function __construct(
        private readonly float $priorSd = 1.5,
        float $gridMin = -4.0,
        float $gridMax = 4.0,
        float $gridStep = 0.05,
    ) {
        $steps = (int) round(($gridMax - $gridMin) / $gridStep);
        for ($i = 0; $i <= $steps; $i++) {
            $this->grid[] = $gridMin + $i * $gridStep;
        }
    }

    public static function fromConfig(): self
    {
        $c = config('learning.assessment');

        return new self($c['prior_sd'], $c['grid_min'], $c['grid_max'], $c['grid_step']);
    }

    public function probability(float $theta, float $difficulty, int $optionCount): float
    {
        $c = $optionCount > 1 ? 1 / $optionCount : 0.0;

        return $c + (1 - $c) / (1 + exp(-($theta - $difficulty)));
    }

    /** Fisher information of an item at theta. */
    public function information(float $theta, float $difficulty, int $optionCount): float
    {
        $c = $optionCount > 1 ? 1 / $optionCount : 0.0;
        $logistic = 1 / (1 + exp(-($theta - $difficulty)));
        $p = $c + (1 - $c) * $logistic;
        $derivative = (1 - $c) * $logistic * (1 - $logistic);

        return ($derivative ** 2) / max($p * (1 - $p), 1e-12);
    }

    /**
     * Expected-a-posteriori estimate.
     *
     * @param  list<array{difficulty: float, options: int, correct: bool}>  $responses
     * @return array{theta: float, se: float}
     */
    public function estimate(array $responses, float $priorMean): array
    {
        $logPosterior = [];
        foreach ($this->grid as $i => $theta) {
            $lp = -0.5 * (($theta - $priorMean) / $this->priorSd) ** 2;
            foreach ($responses as $r) {
                $p = $this->probability($theta, $r['difficulty'], $r['options']);
                $lp += $r['correct'] ? log($p) : log(1 - $p);
            }
            $logPosterior[$i] = $lp;
        }

        $max = max($logPosterior);
        $sum = $mean = 0.0;
        foreach ($this->grid as $i => $theta) {
            $w = exp($logPosterior[$i] - $max);
            $sum += $w;
            $mean += $w * $theta;
        }
        $mean /= $sum;

        $variance = 0.0;
        foreach ($this->grid as $i => $theta) {
            $variance += exp($logPosterior[$i] - $max) * ($theta - $mean) ** 2;
        }
        $variance /= $sum;

        return ['theta' => round($mean, 3), 'se' => round(sqrt($variance), 3)];
    }

    /** 0–100 position on the platform ability scale (not a percentage correct). */
    public function scaledScore(float $theta): int
    {
        return (int) max(0, min(100, round(($theta + 4) / 8 * 100)));
    }

    public function confidence(float $se): string
    {
        $c = config('learning.assessment.confidence', ['high' => 0.5, 'medium' => 0.8]);

        return $se <= $c['high'] ? 'high' : ($se <= $c['medium'] ? 'medium' : 'low');
    }
}
