<?php

namespace Tests\Unit;

use App\Domain\Practice\AnswerChecker;
use App\Domain\Practice\TextNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AnswerCheckerTest extends TestCase
{
    #[Test]
    public function normalisation_is_language_agnostic(): void
    {
        $this->assertSame('hola qué tal', TextNormalizer::normalize('  ¡Hola,  Qué   tal!  '));
        $this->assertSame("l'eau", TextNormalizer::normalize('L’eau'));
        $this->assertSame('esta', TextNormalizer::stripMarks('Está'));
        $this->assertSame('كتب', TextNormalizer::normalize('كَتَبَ'), 'Arabic short vowels are optional');
        $this->assertSame('كتاب', TextNormalizer::normalize('كتـــاب'), 'tatweel is ignored');
    }

    #[Test]
    public function typed_answers_get_partial_credit_for_missing_accents_only(): void
    {
        $c = new AnswerChecker;
        $this->assertTrue($c->typed('Está', ['está'])['is_correct']);
        $near = $c->typed('esta', ['está']);
        $this->assertFalse($near['is_correct']);
        $this->assertSame(0.5, $near['score']);
        $this->assertSame('check_accents', $near['feedback_code']);
        $this->assertSame(0.0, $c->typed('estoy', ['está'])['score']);
        $this->assertSame('empty', $c->typed('  ', ['está'])['feedback_code']);
        $this->assertTrue($c->typed('أَدْرُسُ', ['أدرس'])['is_correct'], 'vowelled Arabic matches unvowelled key');
        $this->assertSame('check_accents', $c->typed('ادرس', ['أدرس'])['feedback_code'], 'missing hamza is a near miss');
    }

    #[Test]
    public function dictation_uses_word_accuracy(): void
    {
        $c = new AnswerChecker;
        $this->assertSame(1.0, $c->dictation('Me llamo Ana.', 'me llamo ana')['score']);
        $oneWrong = $c->dictation('el gato es negro y grande', 'el gato es negro y pequeño');
        $this->assertEqualsWithDelta(5 / 6, $oneWrong['score'], 0.001);
        $this->assertFalse($oneWrong['is_correct']);
        $this->assertTrue($c->dictation('uno dos tres cuatro cinco seis siete ocho nueve diez', 'uno dos tres cuatro cinco seis siete ocho nueve once')['is_correct']);
    }
}
