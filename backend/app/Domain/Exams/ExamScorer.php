<?php

namespace App\Domain\Exams;

/**
 * Pure scoring helpers for exam practice. Every output is an estimate with its uncertainty:
 *  - band scales (e.g. IELTS): ability → band by piecewise-linear interpolation of an indicative
 *    conversion table, reported with a ±1 standard-error range and rounded to half bands;
 *  - readiness scales (level exams): probability that ability exceeds the target level's cut point,
 *    P = Φ((θ − cut) / SE), reported as likely / borderline / not yet.
 */
final class ExamScorer
{
    /** @param list<array{theta: float, value: float}> $points ordered by theta */
    public static function band(float $theta, array $points): float
    {
        if ($points === []) {
            return 0.0;
        }
        if ($theta <= $points[0]['theta']) {
            return $points[0]['value'];
        }
        foreach ($points as $i => $p) {
            if ($i === 0) {
                continue;
            }
            $prev = $points[$i - 1];
            if ($theta <= $p['theta']) {
                $t = ($theta - $prev['theta']) / max(1e-9, $p['theta'] - $prev['theta']);

                return $prev['value'] + $t * ($p['value'] - $prev['value']);
            }
        }

        return end($points)['value'];
    }

    /** Round to the nearest half band, halves rounding up (x.25 → x.5, x.75 → x+1), as band scales are reported. */
    public static function halfBand(float $value): float
    {
        return floor($value * 2 + 0.5 + 1e-9) / 2;
    }

    /** @return array{band: float, low: float, high: float} */
    public static function bandEstimate(float $theta, float $se, array $points): array
    {
        return [
            'band' => self::halfBand(self::band($theta, $points)),
            'low' => self::halfBand(self::band($theta - $se, $points)),
            'high' => self::halfBand(self::band($theta + $se, $points)),
        ];
    }

    /** @return array{probability: float, label: string} */
    public static function readiness(float $theta, float $se, float $cut): array
    {
        $p = self::normalCdf(($theta - $cut) / max(0.05, $se));

        return ['probability' => round($p, 3), 'label' => self::label($p)];
    }

    public static function label(float $p): string
    {
        return match (true) {
            $p >= 0.7 => 'likely',
            $p >= 0.4 => 'borderline',
            default => 'not_yet',
        };
    }

    /**
     * Overall result following the exam's own published aggregation rule.
     *  - mean_half_band: mean of the four section bands rounded to the nearest half band (needs every section);
     *  - all_sections: a level exam requires every part — combined chance = product of section chances.
     *
     * @param  list<array<string, mixed>>  $sections  estimates of scored sections
     * @return array<string, mixed>|null
     */
    public static function overall(string $rule, array $sections, int $expected): ?array
    {
        if ($sections === [] || count($sections) < $expected) {
            return null; // partial attempt: no overall estimate
        }

        return match ($rule) {
            'mean_half_band' => [
                'band' => self::halfBand(array_sum(array_column($sections, 'band')) / count($sections)),
                'low' => self::halfBand(array_sum(array_column($sections, 'low')) / count($sections)),
                'high' => self::halfBand(array_sum(array_column($sections, 'high')) / count($sections)),
            ],
            default => (function () use ($sections) {
                $p = array_product(array_column($sections, 'probability'));

                return ['probability' => round($p, 3), 'label' => self::label($p)];
            })(),
        };
    }

    /** Standard normal CDF (Abramowitz–Stegun 7.1.26, |error| < 1.5e-7). */
    public static function normalCdf(float $z): float
    {
        $t = 1 / (1 + 0.3275911 * abs($z) / M_SQRT2);
        $erf = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-($z * $z) / 2);

        return $z >= 0 ? (1 + $erf) / 2 : (1 - $erf) / 2;
    }
}
