<?php

namespace App\Domain\Practice;

use Normalizer;

/**
 * Language-agnostic text normalisation for comparing learner input. Script-specific details
 * (Arabic tashkeel/tatweel, Latin diacritics) are handled by Unicode properties, not per-language code.
 */
final class TextNormalizer
{
    /** Scripts written without spaces between words: each character is a token (and the counting unit). */
    public const UNSPACED = '\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}';

    public static function isUnspaced(string $text): bool
    {
        return preg_match('/['.self::UNSPACED.']/u', $text) === 1;
    }

    /** Case-folded, whitespace-collapsed, punctuation-trimmed, NFC. Keeps diacritics. */
    public static function normalize(string $text): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        // Full-width ASCII (common in CJK input methods) → half-width: "ＡＢ１" → "AB1".
        $text = preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', fn ($m) => mb_chr(mb_ord($m[0]) - 0xFEE0), $text) ?? $text;
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

    /**
     * Word tokens. Runs of unspaced scripts (Chinese, Japanese, Thai…) are split into single characters,
     * which is also how those languages count text length; dictionary words are found with segment().
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $normalized = self::normalize($text);
        if ($normalized === '') {
            return [];
        }
        $tokens = explode(' ', $normalized);
        if (! self::isUnspaced($normalized)) {
            return $tokens;
        }
        $out = [];
        foreach ($tokens as $token) {
            foreach (preg_split('/(['.self::UNSPACED.'])/u', $token, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * Candidate dictionary words inside unspaced runs: every substring up to $max characters.
     *
     * @return list<string>
     */
    public static function candidates(string $text, int $max = 6): array
    {
        $found = [];
        preg_match_all('/['.self::UNSPACED.']+/u', self::normalize($text), $runs);
        foreach ($runs[0] as $run) {
            $chars = mb_str_split($run);
            $n = count($chars);
            for ($i = 0; $i < $n; $i++) {
                for ($len = 1; $len <= $max && $i + $len <= $n; $len++) {
                    $found[implode('', array_slice($chars, $i, $len))] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Greedy longest-match segmentation of unspaced runs against a dictionary predicate
     * (spaced text is returned as its words). Unknown characters become single-character tokens.
     *
     * @param  callable(string): bool  $isWord
     * @return list<string>
     */
    public static function segment(string $text, callable $isWord, int $max = 6): array
    {
        $out = [];
        foreach (explode(' ', self::normalize($text)) as $token) {
            if ($token === '') {
                continue;
            }
            if (! self::isUnspaced($token)) {
                $out[] = $token;

                continue;
            }
            $chars = mb_str_split($token);
            $i = 0;
            while ($i < count($chars)) {
                $len = min($max, count($chars) - $i);
                while ($len > 1 && ! $isWord(implode('', array_slice($chars, $i, $len)))) {
                    $len--;
                }
                $out[] = implode('', array_slice($chars, $i, $len));
                $i += $len;
            }
        }

        return $out;
    }
}
