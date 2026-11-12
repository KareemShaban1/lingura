<?php

namespace Tests\Unit;

use App\Domain\Speech\PronunciationScorer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PronunciationScorerTest extends TestCase
{
    #[Test]
    public function perfect_reading_scores_full(): void
    {
        $r = (new PronunciationScorer)->score('Mi casa es pequeña.', 'mi casa es pequena');
        $this->assertSame(1.0, $r['accuracy'], 'recogniser accent quirks are not errors');
        $this->assertSame(['correct', 'correct', 'correct', 'correct'], array_column($r['words'], 'status'));
    }

    #[Test]
    public function substitutions_omissions_and_insertions_are_aligned(): void
    {
        $r = (new PronunciationScorer)->score('nosotros estudiamos por la noche', 'nosotros estudiamos la la noches');
        $statuses = array_column($r['words'], 'status');

        $this->assertSame(0.6, $r['accuracy']); // nosotros, estudiamos, la
        $this->assertContains('substituted', $statuses);
        $this->assertSame(5, count(array_filter($r['words'], fn ($w) => $w['expected'] !== null)));
    }

    #[Test]
    public function silence_scores_zero(): void
    {
        $r = (new PronunciationScorer)->score('Me llamo Ana', '');
        $this->assertSame(0.0, $r['accuracy']);
        $this->assertSame(0.0, $r['completeness']);
        $this->assertSame(['omitted', 'omitted', 'omitted'], array_column($r['words'], 'status'));
    }
}
