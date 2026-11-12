<?php

namespace App\Domain\Speech;

use App\Domain\Practice\TextNormalizer;

/**
 * Transcript-alignment pronunciation estimate (method `transcript_alignment_v1`).
 *
 * The learner's recording is transcribed by a speech recogniser that is NOT primed with the reference
 * sentence; the transcript is then aligned word-by-word with the reference. A word the recogniser
 * understood counts as intelligible. This measures intelligibility, not phoneme-level accuracy —
 * the UI labels it accordingly. Phoneme scoring can be plugged in via PronunciationAssessor later.
 */
final class PronunciationScorer
{
    /**
     * @return array{accuracy: float, completeness: float, words: list<array{expected: ?string, heard: ?string, status: string}>}
     */
    public function score(string $reference, string $heard): array
    {
        $ref = TextNormalizer::words($reference);
        $hyp = TextNormalizer::words($heard);
        $n = count($ref);
        $m = count($hyp);

        // Levenshtein over words with backtrace. Equality ignores accents so recogniser spelling quirks don't count as errors.
        $eq = fn (string $a, string $b) => TextNormalizer::stripMarks($a) === TextNormalizer::stripMarks($b);
        $d = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = 0; $i <= $n; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $m; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + ($eq($ref[$i - 1], $hyp[$j - 1]) ? 0 : 1));
            }
        }

        $words = [];
        [$i, $j] = [$n, $m];
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $d[$i][$j] === $d[$i - 1][$j - 1] + ($eq($ref[$i - 1], $hyp[$j - 1]) ? 0 : 1)) {
                $words[] = ['expected' => $ref[$i - 1], 'heard' => $hyp[$j - 1], 'status' => $eq($ref[$i - 1], $hyp[$j - 1]) ? 'correct' : 'substituted'];
                $i--;
                $j--;
            } elseif ($i > 0 && $d[$i][$j] === $d[$i - 1][$j] + 1) {
                $words[] = ['expected' => $ref[$i - 1], 'heard' => null, 'status' => 'omitted'];
                $i--;
            } else {
                $words[] = ['expected' => null, 'heard' => $hyp[$j - 1], 'status' => 'inserted'];
                $j--;
            }
        }
        $words = array_reverse($words);
        $correct = count(array_filter($words, fn ($w) => $w['status'] === 'correct'));
        $attempted = count(array_filter($words, fn ($w) => in_array($w['status'], ['correct', 'substituted'], true)));

        return [
            'accuracy' => $n ? round($correct / $n, 4) : 0.0,
            'completeness' => $n ? round($attempted / $n, 4) : 0.0,
            'words' => $words,
        ];
    }
}
