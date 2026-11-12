<?php

namespace App\Domain\Learning;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainError;
use App\Domain\Shared\LearningEventRecorder;
use App\Models\CefrLevel;
use App\Models\Language;
use App\Models\LanguageVariant;
use App\Models\LearnerProfile;
use App\Models\LearningPlan;
use App\Models\LearningPurpose;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Onboarding and learner-profile settings. */
class LearnerProfileService
{
    public function __construct(
        private readonly PlanGenerator $generator,
        private readonly LearningEventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    public function create(User $user, array $data): LearnerProfile
    {
        $language = Language::active()->where('is_target_enabled', true)->where('code', $data['target_language'])->firstOrFail();

        if ($user->learnerProfiles()->where('target_language_id', $language->id)->exists()) {
            throw new DomainError('You are already learning this language.', 'profile_exists');
        }

        $profile = DB::transaction(function () use ($user, $data, $language) {
            $profile = $user->learnerProfiles()->create([
                'target_language_id' => $language->id,
                'language_variant_id' => $this->variantId($language, $data['variant'] ?? null),
                'learning_purpose_id' => LearningPurpose::where('slug', $data['purpose'])->value('id'),
                'self_reported_level_id' => isset($data['self_reported_level']) ? CefrLevel::where('code', $data['self_reported_level'])->value('id') : null,
                'target_level_id' => isset($data['target_level']) ? CefrLevel::where('code', $data['target_level'])->value('id') : null,
                'daily_minutes' => $data['daily_minutes'],
                'status' => LearnerProfile::STATUS_ASSESSMENT_PENDING,
                'onboarded_at' => now(),
            ]);
            $this->syncDetails($profile, $data);
            $this->events->record($profile, 'onboarding.completed', $profile, metadata: ['purpose' => $data['purpose']]);

            return $profile;
        });

        // A useful plan exists immediately (it starts with the placement assessment).
        $this->generator->generate($profile, 'initial');

        return $profile;
    }

    public function update(LearnerProfile $profile, array $data): LearnerProfile
    {
        $before = $profile->only(['learning_purpose_id', 'daily_minutes', 'target_level_id', 'language_variant_id']);

        DB::transaction(function () use ($profile, $data) {
            $attributes = [];
            if (array_key_exists('purpose', $data)) {
                $attributes['learning_purpose_id'] = LearningPurpose::where('slug', $data['purpose'])->value('id');
            }
            if (array_key_exists('daily_minutes', $data)) {
                $attributes['daily_minutes'] = $data['daily_minutes'];
            }
            if (array_key_exists('target_level', $data)) {
                $attributes['target_level_id'] = $data['target_level'] ? CefrLevel::where('code', $data['target_level'])->value('id') : null;
            }
            if (array_key_exists('variant', $data)) {
                $attributes['language_variant_id'] = $this->variantId($profile->targetLanguage, $data['variant']);
            }
            $profile->update($attributes);
            $this->syncDetails($profile, $data);
        });

        $profile->refresh();
        $this->audit->log('learner_profile.updated', $profile, $before, $profile->only(array_keys($before)));

        $planRelevant = array_intersect(array_keys($data), ['purpose', 'daily_minutes', 'target_level', 'schedule', 'focus_skills']);
        if ($planRelevant !== [] && $profile->plan()->where('status', LearningPlan::STATUS_ACTIVE)->exists()) {
            $this->generator->generate($profile, 'settings_change');
        }

        return $profile;
    }

    public function archive(LearnerProfile $profile): void
    {
        DB::transaction(function () use ($profile) {
            $profile->plan()->update(['status' => LearningPlan::STATUS_ARCHIVED]);
            $profile->update(['status' => LearnerProfile::STATUS_ARCHIVED]);
            $profile->delete();
        });
        $this->audit->log('learner_profile.archived', $profile);
    }

    private function syncDetails(LearnerProfile $profile, array $data): void
    {
        if (array_key_exists('focus_skills', $data)) {
            $profile->focusSkills()->sync(Skill::whereIn('slug', $data['focus_skills'] ?? [])->pluck('id'));
        }
        if (array_key_exists('schedule', $data)) {
            $profile->scheduleSlots()->delete();
            foreach ($data['schedule'] ?? [] as $slot) {
                $profile->scheduleSlots()->create(['day_of_week' => $slot['day'], 'preferred_time' => $slot['time'] ?? 'evening']);
            }
        }
        if (array_key_exists('preferences', $data)) {
            foreach ($data['preferences'] ?? [] as $key => $value) {
                $profile->preferences()->updateOrCreate(['preference_key' => $key], ['value' => (string) $value]);
            }
        }
    }

    private function variantId(Language $language, ?string $code): ?int
    {
        if ($code === null) {
            return LanguageVariant::where('language_id', $language->id)->where('is_default', true)->value('id');
        }

        return LanguageVariant::where('language_id', $language->id)->where('code', $code)->value('id')
            ?? throw new DomainError('That regional variant is not available for this language.', 'invalid_variant', 422);
    }
}
