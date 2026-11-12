<?php

namespace App\Models\Concerns;

use App\Models\Translation;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Localised content fields stored in `translations`. Falls back to English, then any locale.
 */
trait HasTranslations
{
    public function translations(): MorphMany
    {
        return $this->morphMany(Translation::class, 'translatable');
    }

    public function translate(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $rows = $this->translations->where('field', $field);

        return $rows->firstWhere('locale', $locale)?->value
            ?? $rows->firstWhere('locale', 'en')?->value
            ?? $rows->first()?->value;
    }

    /** @param array<string, string> $values locale => text */
    public function setTranslations(string $field, array $values): void
    {
        foreach ($values as $locale => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $this->translations()->updateOrCreate(['field' => $field, 'locale' => $locale], ['value' => $value]);
        }
        $this->unsetRelation('translations');
    }
}
