<?php

namespace Tests\Unit;

use App\Domain\Assessment\Engine\CefrScale;
use Tests\TestCase;

class CefrScaleTest extends TestCase
{
    public function test_theta_maps_to_levels_using_midpoint_cuts(): void
    {
        $scale = CefrScale::load();

        $this->assertSame('PRE_A1', $scale->levelForTheta(-3.2)->code);
        $this->assertSame('A1', $scale->levelForTheta(-2.5)->code);
        $this->assertSame('A2', $scale->levelForTheta(-1.0)->code);
        $this->assertSame('B1', $scale->levelForTheta(0.49)->code);
        $this->assertSame('B2', $scale->levelForTheta(0.5)->code);
        $this->assertSame('C2', $scale->levelForTheta(3.9)->code);
    }

    public function test_overall_level_is_the_lower_median_not_the_mean(): void
    {
        $scale = CefrScale::load();

        // Mean of [1, 1, 5, 5] would be 3 (B1). The lower median is 1 — never rounded up past real evidence.
        $this->assertSame(1, $scale->lowerMedian([5, 1, 5, 1]));
        $this->assertSame(3, $scale->lowerMedian([2, 3, 6]));
        $this->assertSame(4, $scale->lowerMedian([4]));
        $this->assertNull($scale->lowerMedian([]));
    }
}
