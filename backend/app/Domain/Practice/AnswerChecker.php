<?php

namespace App\Domain\Practice;

use App\Models\ExerciseQuestion;

/**
 * Deterministic scoring for every Phase 2 exercise type — no AI involved in correctness.
 *
 * @phpstan-type Result array{is_correct: bool, score: float, feedback_code: ?string}
 */
final class AnswerChecker
{
    /** Dictation is accepted at or above this word accuracy. */
    public const DICTATION_PASS = 0.9;

    /** @return array{is_correct: bool, score: float, feedback_code: ?string} */
    public function choice(ExerciseQuestion $question, int $optionId): array
    {
        $option = $question->options->firstWhere('id', $optionId);
        $correct = (bool) $option?->is_correct;

        return ['is_correct' => $correct, 'score' => $correct ? 1.0 : 0.0, 'feedback_code' => null];
    }

    /**
     * Typed answer against accepted answers. An answer that differs only in accents/diacritics
     * earns half credit and a specific hint instead of a plain "wrong".
     *
     * @param  list<string>  $accepted
     * @return array{is_correct: bool, score: float, feedback_code: ?string}
     */
    public function typed(string $response, array $accepted): array
    {
        $given = TextNormalizer::normalize($response);
        if ($given === '') {
            return ['is_correct' => false, 'score' => 0.0, 'feedback_code' => 'empty'];
        }
        foreach ($accepted as $answer) {
            if ($given === TextNormalizer::normalize($answer)) {
                return ['is_correct' => true, 'score' => 1.0, 'feedback_code' => null];
            }
        }
        foreach ($accepted as $answer) {
            if (TextNormalizer::stripMarks($response) === TextNormalizer::stripMarks($answer)) {
                return ['is_correct' => false, 'score' => 0.5, 'feedback_code' => 'check_accents'];
            }
        }

        return ['is_correct' => false, 'score' => 0.0, 'feedback_code' => null];
    }

    /**
     * Word-order exercise: the submitted option ids must match the correct sequence exactly.
     * Partial credit = longest correctly ordered prefix share (simple and explainable).
     *
     * @param  list<int>  $orderedOptionIds
     * @return array{is_correct: bool, score: float, feedback_code: ?string}
     */
    public function ordering(ExerciseQuestion $question, array $orderedOptionIds): array
    {
        $expected = $question->options->sortBy('correct_position')->pluck('id')->values()->all();
        if (count($orderedOptionIds) !== count($expected) || array_diff($expected, $orderedOptionIds) !== []) {
            return ['is_correct' => false, 'score' => 0.0, 'feedback_code' => 'invalid_order'];
        }
        if ($orderedOptionIds === $expected) {
            return ['is_correct' => true, 'score' => 1.0, 'feedback_code' => null];
        }
        $prefix = 0;
        while ($orderedOptionIds[$prefix] === $expected[$prefix]) {
            $prefix++;
        }

        return ['is_correct' => false, 'score' => round($prefix / count($expected), 4), 'feedback_code' => null];
    }

    /**
     * Dictation: word accuracy = 1 − (word-level edit distance / reference length).
     *
     * @return array{is_correct: bool, score: float, feedback_code: ?string}
     */
    public function dictation(string $response, string $reference): array
    {
        $ref = TextNormalizer::words($reference);
        $hyp = TextNormalizer::words($response);
        if ($ref === []) {
            return ['is_correct' => false, 'score' => 0.0, 'feedback_code' => null];
        }
        $accuracy = max(0.0, 1 - $this->editDistance($ref, $hyp) / count($ref));
        $accuracy = round($accuracy, 4);

        return [
            'is_correct' => $accuracy >= self::DICTATION_PASS,
            'score' => $accuracy,
            'feedback_code' => $accuracy < 1 ? 'word_accuracy' : null,
        ];
    }

    /** @param list<string> $a @param list<string> $b */
    private function editDistance(array $a, array $b): int
    {
        $prev = range(0, count($b));
        foreach ($a as $i => $wa) {
            $cur = [$i + 1];
            foreach ($b as $j => $wb) {
                $cur[$j + 1] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($wa === $wb ? 0 : 1));
            }
            $prev = $cur;
        }

        return $prev[count($b)];
    }
}
