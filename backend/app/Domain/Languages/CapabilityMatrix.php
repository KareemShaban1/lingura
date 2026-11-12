<?php

namespace App\Domain\Languages;

use App\Models\Language;
use App\Models\LanguageCapability;
use Illuminate\Support\Facades\Cache;

/**
 * Per-language feature availability. Features are never assumed to work equally for all languages.
 */
class CapabilityMatrix
{
    private const CACHE_KEY = 'language-capabilities:v1';

    /** @return array<string, array<string, array{status:string, quality_tier:int}>> code => feature => info */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), function () {
            $matrix = [];
            LanguageCapability::query()
                ->join('languages', 'languages.id', '=', 'language_capabilities.language_id')
                ->where('provider_slug', 'default')
                ->get(['languages.code', 'feature', 'status', 'quality_tier'])
                ->each(function ($row) use (&$matrix) {
                    $matrix[$row->code][$row->feature] = ['status' => $row->status, 'quality_tier' => (int) $row->quality_tier];
                });

            return $matrix;
        });
    }

    public function forLanguage(Language|string $language): array
    {
        $code = $language instanceof Language ? $language->code : $language;
        $features = $this->all()[$code] ?? [];

        foreach (LanguageCapability::FEATURES as $feature) {
            $features[$feature] ??= ['status' => LanguageCapability::UNSUPPORTED, 'quality_tier' => 3];
        }

        return $features;
    }

    public function status(string $languageCode, string $feature): string
    {
        return $this->forLanguage($languageCode)[$feature]['status'];
    }

    public function isUsable(string $languageCode, string $feature): bool
    {
        return in_array($this->status($languageCode, $feature), [LanguageCapability::SUPPORTED, LanguageCapability::BETA], true);
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
