<?php

namespace App\Domain\AI;

use App\Models\LanguagePair;
use App\Models\LearnerProfile;

/**
 * Language used for corrections and explanations: the learner's native language when the
 * native→target pair supports explanations, otherwise their interface language.
 */
final class ExplanationLanguage
{
    public static function for(LearnerProfile $profile): string
    {
        $profile->loadMissing(['user.profile.nativeLanguage', 'user.profile.interfaceLanguage']);
        $native = $profile->user->profile?->nativeLanguage;
        if ($native && $native->id !== $profile->target_language_id) {
            $supported = LanguagePair::where('source_language_id', $native->id)
                ->where('target_language_id', $profile->target_language_id)
                ->where('is_active', true)->exists();
            if ($supported) {
                return $native->name;
            }
        }

        return $profile->user->profile?->interfaceLanguage?->name ?? 'English';
    }
}
