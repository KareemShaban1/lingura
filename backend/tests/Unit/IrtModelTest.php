<?php

namespace Tests\Unit;

use App\Domain\Assessment\Engine\IrtModel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IrtModelTest extends TestCase
{
    private IrtModel $irt;

    protected function setUp(): void
    {
        $this->irt = new IrtModel(priorSd: 1.5);
    }

    #[Test]
    public function probability_has_guessing_floor_and_is_monotonic(): void
    {
        $low = $this->irt->probability(-10, 0, 4);
        $mid = $this->irt->probability(0, 0, 4);
        $high = $this->irt->probability(10, 0, 4);

        $this->assertEqualsWithDelta(0.25, $low, 0.001);
        $this->assertEqualsWithDelta(0.625, $mid, 0.001);
        $this->assertEqualsWithDelta(1.0, $high, 0.001);
        $this->assertLessThan($mid, $low);
        $this->assertLessThan($high, $mid);
    }

    #[Test]
    public function information_is_highest_near_item_difficulty(): void
    {
        $near = $this->irt->information(0.3, 0.0, 4);
        $far = $this->irt->information(3.0, 0.0, 4);

        $this->assertGreaterThan($far, $near);
    }

    #[Test]
    public function estimate_moves_with_evidence_and_uncertainty_shrinks(): void
    {
        $prior = $this->irt->estimate([], 0.0);
        $this->assertEqualsWithDelta(0.0, $prior['theta'], 0.01);
        $this->assertEqualsWithDelta(1.5, $prior['se'], 0.05);

        $allCorrect = $this->irt->estimate(array_fill(0, 6, ['difficulty' => 1.0, 'options' => 4, 'correct' => true]), 0.0);
        $allWrong = $this->irt->estimate(array_fill(0, 6, ['difficulty' => -1.0, 'options' => 4, 'correct' => false]), 0.0);

        $this->assertGreaterThan(1.0, $allCorrect['theta']);
        $this->assertLessThan(-1.0, $allWrong['theta']);
        $this->assertLessThan($prior['se'], $allCorrect['se']);
    }

    #[Test]
    public function scaled_score_and_confidence_are_bounded_and_documented(): void
    {
        $this->assertSame(0, $this->irt->scaledScore(-9));
        $this->assertSame(50, $this->irt->scaledScore(0));
        $this->assertSame(100, $this->irt->scaledScore(9));
    }
}
