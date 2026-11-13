<?php

namespace App\Domain\Personalization;

/**
 * Small deterministic PRNG (SplitMix64-style on 32-bit halves) so a plan generated twice from the same
 * state makes the same choices. Never touches PHP's global mt_rand state.
 */
final class SeededRandom
{
    private int $state;

    public function __construct(string $seed)
    {
        $this->state = (int) hexdec(substr(hash('sha256', $seed), 0, 7));
    }

    /** Uniform in (0, 1). */
    public function next(): float
    {
        // Mulberry32
        $this->state = ($this->state + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->state;
        $t = $this->imul($t ^ ($t >> 15), $t | 1);
        $t ^= ($t + $this->imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF;
        $t = ($t ^ ($t >> 14)) & 0xFFFFFFFF;

        return ($t + 0.5) / 4294967296;
    }

    public function normal(): float
    {
        return sqrt(-2 * log($this->next())) * cos(2 * M_PI * $this->next());
    }

    /** Gamma(shape, 1) by Marsaglia–Tsang (with the shape < 1 boost). */
    public function gamma(float $shape): float
    {
        if ($shape < 1) {
            return $this->gamma($shape + 1) * $this->next() ** (1 / $shape);
        }
        $d = $shape - 1 / 3;
        $c = 1 / sqrt(9 * $d);
        while (true) {
            do {
                $x = $this->normal();
                $v = 1 + $c * $x;
            } while ($v <= 0);
            $v = $v ** 3;
            $u = $this->next();
            if ($u < 1 - 0.0331 * $x ** 4 || log($u) < 0.5 * $x * $x + $d * (1 - $v + log($v))) {
                return $d * $v;
            }
        }
    }

    public function beta(float $a, float $b): float
    {
        $x = $this->gamma($a);

        return $x / ($x + $this->gamma($b));
    }

    private function imul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $lo = (($a & 0xFFFF) * $b) & 0xFFFFFFFF;
        $hi = ((($a >> 16) * $b) & 0xFFFF) << 16;

        return ($lo + $hi) & 0xFFFFFFFF;
    }
}
