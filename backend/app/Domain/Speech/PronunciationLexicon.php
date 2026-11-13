<?php

namespace App\Domain\Speech;

use App\Domain\Practice\TextNormalizer;
use App\Models\Language;
use App\Models\VocabularyWord;
use Illuminate\Support\Facades\Cache;

/**
 * Character → pinyin readings derived from the language's own lexicon (each word's pinyin aligned
 * syllable-by-syllable with its characters). Only languages whose lexicon carries pinyin get one;
 * polyphonic characters use their most frequent reading in the lexicon.
 */
class PronunciationLexicon
{
    /** @return array<string, string> character => pinyin syllable */
    public function readings(string $languageCode): array
    {
        return Cache::remember("pron-lexicon:{$languageCode}", now()->addHour(), function () use ($languageCode) {
            $language = Language::where('code', $languageCode)->first();
            if (! $language || ! in_array($language->script, ['Hans', 'Hant'], true)) {
                return [];
            }
            $counts = [];
            VocabularyWord::where('language_id', $language->id)->whereNotNull('ipa')->orderBy('frequency_rank')
                ->get(['lemma', 'ipa'])->each(function (VocabularyWord $w) use (&$counts) {
                    $chars = TextNormalizer::words($w->lemma);
                    $syllables = Pinyin::syllables($w->ipa);
                    if (count($chars) !== count($syllables)) {
                        return; // cannot align safely
                    }
                    foreach ($chars as $i => $char) {
                        $counts[$char][$syllables[$i]] = ($counts[$char][$syllables[$i]] ?? 0) + 1;
                    }
                });

            return array_map(function (array $readings) {
                arsort($readings);

                return (string) array_key_first($readings);
            }, $counts);
        });
    }

    public function flush(string $languageCode): void
    {
        Cache::forget("pron-lexicon:{$languageCode}");
    }
}
