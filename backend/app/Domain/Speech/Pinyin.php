<?php

namespace App\Domain\Speech;

use Normalizer;

/** Hanyu Pinyin syllables with tone marks: tone number and toneless base ("hǎo" → 3, "hao"). */
final class Pinyin
{
    private const TONES = ["\u{0304}" => 1, "\u{0301}" => 2, "\u{030C}" => 3, "\u{0300}" => 4];

    public static function tone(string $syllable): int
    {
        $decomposed = Normalizer::normalize(mb_strtolower($syllable), Normalizer::FORM_D) ?: $syllable;
        foreach (self::TONES as $mark => $tone) {
            if (str_contains($decomposed, $mark)) {
                return $tone;
            }
        }

        return 5; // neutral tone
    }

    public static function base(string $syllable): string
    {
        $decomposed = Normalizer::normalize(mb_strtolower(trim($syllable)), Normalizer::FORM_D) ?: $syllable;
        // Drop tone marks but keep the diaeresis of ü (lü ≠ lu).
        $base = str_replace(array_keys(self::TONES), '', $decomposed);

        return Normalizer::normalize($base, Normalizer::FORM_C) ?: $base;
    }

    /** @return list<string> "nǐ hǎo" / "nǐhǎo" with apostrophes → syllables split on spaces and apostrophes. */
    public static function syllables(string $pinyin): array
    {
        return array_values(array_filter(preg_split("/[\\s'’-]+/u", trim($pinyin)) ?: [], fn ($s) => $s !== ''));
    }
}
