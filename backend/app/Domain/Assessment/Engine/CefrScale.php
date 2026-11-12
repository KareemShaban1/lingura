<?php

namespace App\Domain\Assessment\Engine;

use App\Models\CefrLevel;
use Illuminate\Support\Collection;

/**
 * Maps ability (theta) to CEFR levels using cut points halfway between adjacent level anchors.
 */
final class CefrScale
{
    /** @param Collection<int, CefrLevel> $levels ordered by ordinal */
    public function __construct(private readonly Collection $levels) {}

    public static function load(): self
    {
        return new self(CefrLevel::orderBy('ordinal')->get());
    }

    public function levelForTheta(float $theta): CefrLevel
    {
        $chosen = $this->levels->first();
        foreach ($this->levels->values() as $i => $level) {
            if ($i === 0) {
                continue;
            }
            $previous = $this->levels->values()[$i - 1];
            $cut = ($previous->anchor_theta + $level->anchor_theta) / 2;
            if ($theta >= $cut) {
                $chosen = $level;
            }
        }

        return $chosen;
    }

    public function byOrdinal(int $ordinal): CefrLevel
    {
        $ordinal = max(0, min($ordinal, $this->levels->max('ordinal')));

        return $this->levels->firstWhere('ordinal', $ordinal);
    }

    public function byId(?int $id): ?CefrLevel
    {
        return $id ? $this->levels->firstWhere('id', $id) : null;
    }

    /**
     * Overall level = lower median of assessed skill ordinals. Never an arithmetic mean.
     *
     * @param  list<int>  $ordinals
     */
    public function lowerMedian(array $ordinals): ?int
    {
        if ($ordinals === []) {
            return null;
        }
        sort($ordinals);

        return $ordinals[intdiv(count($ordinals) - 1, 2)];
    }
}
