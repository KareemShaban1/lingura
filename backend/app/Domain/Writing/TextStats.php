<?php

namespace App\Domain\Writing;

use App\Domain\Practice\TextNormalizer;

/** Deterministic text measures reported alongside AI rubric scores. */
final class TextStats
{
    /** @return array{words: int, sentences: int, avg_sentence_length: float, lexical_variety: float} */
    public static function of(string $text): array
    {
        $words = TextNormalizer::words($text);
        $sentences = max(1, count(array_filter(preg_split('/[.!?؟。]+/u', $text) ?: [], fn ($s) => trim($s) !== '')));
        $count = count($words);

        return [
            'words' => $count,
            'sentences' => $sentences,
            'avg_sentence_length' => round($count / $sentences, 2),
            'lexical_variety' => $count ? round(count(array_unique($words)) / $count, 4) : 0.0,
        ];
    }
}
