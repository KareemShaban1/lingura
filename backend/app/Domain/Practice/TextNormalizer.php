<?php

namespace App\Domain\Practice;

use Normalizer;

/**
 * Language-agnostic text normalisation for comparing learner input. Script-specific details
 * (Arabic tashkeel/tatweel, Latin diacritics) are handled by Unicode properties, not per-language code.
 */
final class TextNormalizer
{
    /** Case-folded, whitespace-collapsed, punctuation-trimmed, NFC. Keeps diacritics. */
    public static function normalize(string $text): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        $text = str_replace(["\u{2019}", "\u{2018}", "\u{02BC}"], "'", $text);
        $text = preg_replace('/\x{0640}/u', '', $text); // Arabic tatweel is decorative
        // Arabic short vowels/tanween/shadda are optional in normal writing: never required of learners.
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        $text = mb_strtolower($text);
        // Everything except letters, combining marks, digits and apostrophes becomes a space.
        $text = preg_replace('/[^\p{L}\p{M}\p{N}\' ]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** Additionally strips combining marks: "está" → "esta", Arabic short vowels removed. */
    public static function stripMarks(string $text): string
    {
        $decomposed = Normalizer::normalize(self::normalize($text), Normalizer::FORM_D) ?: $text;

        $stripped = Normalizer::normalize(preg_replace('/\p{Mn}+/u', '', $decomposed), Normalizer::FORM_C) ?: $decomposed;

        // Arabic hamza seats and final yaa are frequent learner spelling slips: compare them loosely here.
        return strtr($stripped, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);
    }

    /** @return list<string> word tokens */
    public static function words(string $text): array
    {
        $normalized = self::normalize($text);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }
}
