<?php

namespace Tests\Unit;

use App\Domain\Exams\ExamScorer;
use PHPUnit\Framework\TestCase;

class ExamScorerTest extends TestCase
{
    private const POINTS = [['theta' => 0.0, 'value' => 4.5], ['theta' => 1.0, 'value' => 6.0], ['theta' => 2.0, 'value' => 7.5]];

    public function test_band_interpolates_and_clamps(): void
    {
        $this->assertSame(5.25, ExamScorer::band(0.5, self::POINTS));
        $this->assertSame(4.5, ExamScorer::band(-3.0, self::POINTS));
        $this->assertSame(7.5, ExamScorer::band(5.0, self::POINTS));
    }

    public function test_half_band_rounding_follows_band_reporting(): void
    {
        $this->assertSame(6.5, ExamScorer::halfBand(6.25));
        $this->assertSame(6.0, ExamScorer::halfBand(6.125));
        $this->assertSame(7.0, ExamScorer::halfBand(6.75));
    }

    public function test_band_estimate_reports_a_range(): void
    {
        $e = ExamScorer::bandEstimate(1.0, 0.5, self::POINTS);
        $this->assertSame(['band' => 6.0, 'low' => 5.5, 'high' => 7.0], $e);
    }

    public function test_readiness_uses_uncertainty(): void
    {
        $this->assertSame('borderline', ExamScorer::readiness(-1.5, 0.5, -1.5)['label'], 'exactly at the cut is a coin toss');
        $this->assertSame('likely', ExamScorer::readiness(-0.5, 0.5, -1.5)['label']);
        $this->assertSame('not_yet', ExamScorer::readiness(-2.5, 0.5, -1.5)['label']);
        // The same ability with more uncertainty is less clear-cut.
        $this->assertGreaterThan(ExamScorer::readiness(-1.0, 1.5, -1.5)['probability'], ExamScorer::readiness(-1.0, 0.4, -1.5)['probability']);
    }

    public function test_overall_follows_the_exam_rule_and_needs_every_section(): void
    {
        $bands = [['band' => 6.0, 'low' => 5.5, 'high' => 6.5], ['band' => 6.5, 'low' => 6.0, 'high' => 7.0], ['band' => 5.5, 'low' => 5.0, 'high' => 6.0], ['band' => 7.0, 'low' => 6.5, 'high' => 7.5]];
        $this->assertSame(6.5, ExamScorer::overall('mean_half_band', $bands, 4)['band'], 'mean 6.25 rounds up to 6.5');
        $this->assertNull(ExamScorer::overall('mean_half_band', array_slice($bands, 0, 3), 4), 'no overall for a partial test');

        $parts = [['probability' => 0.9, 'label' => 'likely'], ['probability' => 0.8, 'label' => 'likely']];
        $this->assertSame(0.72, ExamScorer::overall('all_sections', $parts, 2)['probability']);
    }
}
