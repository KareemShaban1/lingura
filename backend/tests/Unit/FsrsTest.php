<?php

namespace Tests\Unit;

use App\Domain\Vocabulary\Fsrs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FsrsTest extends TestCase
{
    #[Test]
    public function first_review_uses_initial_stability_per_rating(): void
    {
        $fsrs = new Fsrs;
        $again = $fsrs->review(null, null, 0, 1);
        $good = $fsrs->review(null, null, 0, 3);
        $easy = $fsrs->review(null, null, 0, 4);

        $this->assertSame(0, $again['interval_days']);
        $this->assertSame('learning', $again['state']);
        $this->assertSame(4, $good['interval_days']);
        $this->assertGreaterThan($good['interval_days'], $easy['interval_days']);
        $this->assertLessThan($again['difficulty'], $easy['difficulty']);
    }

    #[Test]
    public function successful_reviews_grow_intervals_and_lapses_shrink_stability(): void
    {
        $fsrs = new Fsrs;
        $card = $fsrs->review(null, null, 0, 3);
        $intervals = [$card['interval_days']];
        for ($i = 0; $i < 3; $i++) {
            $card = $fsrs->review($card['stability'], $card['difficulty'], $card['interval_days'], 3);
            $intervals[] = $card['interval_days'];
        }
        $this->assertSame($intervals, array_values(array_unique($intervals)));
        $this->assertSame($intervals, collect($intervals)->sort()->values()->all(), 'intervals increase');

        $lapse = $fsrs->review($card['stability'], $card['difficulty'], $card['interval_days'], 1);
        $this->assertLessThan($card['stability'], $lapse['stability']);
        $this->assertSame('relearning', $lapse['state']);
    }

    #[Test]
    public function retrievability_is_ninety_percent_at_one_stability(): void
    {
        $this->assertEqualsWithDelta(0.9, (new Fsrs)->retrievability(10, 10), 0.0001);
    }
}
