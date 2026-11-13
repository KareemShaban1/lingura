<?php

namespace Tests\Feature;

use App\Models\LearnerProfile;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    public function test_catalog_exposes_languages_capabilities_purposes_and_levels(): void
    {
        $response = $this->getJson('/api/v1/catalog/onboarding')->assertOk();

        $codes = collect($response->json('languages'))->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['en', 'ar', 'es', 'fr', 'de', 'it', 'zh'], $codes);

        $arabic = collect($response->json('languages'))->firstWhere('code', 'ar');
        $this->assertSame('rtl', $arabic['direction']);
        $this->assertSame('unsupported', $arabic['capabilities']['pronunciation']['status']);
        $this->assertCount(7, $response->json('purposes'));
        $this->assertSame('PRE_A1', $response->json('levels.0.code'));
    }

    public function test_learner_completes_onboarding_and_gets_an_initial_plan(): void
    {
        $this->actingAsUser($this->makeUser());

        $response = $this->postJson('/api/v1/learner-profiles', [
            'target_language' => 'fr',
            'variant' => 'fr-CA',
            'purpose' => 'career',
            'self_reported_level' => 'A2',
            'target_level' => 'B2',
            'daily_minutes' => 20,
            'focus_skills' => ['speaking', 'writing'],
            'schedule' => [['day' => 1, 'time' => 'morning'], ['day' => 3, 'time' => '19:30'], ['day' => 5]],
            'preferences' => ['explanation_language' => 'ar', 'prefers_audio' => 'yes'],
        ])->assertCreated();

        $response->assertJsonPath('data.target_language.code', 'fr')
            ->assertJsonPath('data.variant.code', 'fr-CA')
            ->assertJsonPath('data.status', LearnerProfile::STATUS_ASSESSMENT_PENDING)
            ->assertJsonPath('data.focus_skills', ['speaking', 'writing']);

        $id = $response->json('data.id');
        $plan = $this->getJson("/api/v1/learner-profiles/{$id}/plan")->assertOk();
        $plan->assertJsonPath('data.current_version.version_number', 1)
            ->assertJsonPath('data.current_version.trigger', 'initial');

        $items = $this->getJson("/api/v1/learner-profiles/{$id}/plan/items")->assertOk()->json('data');
        $this->assertNotEmpty($items);
        $this->assertSame('placement_assessment', $items[0]['activity_type'], 'the first activity is the placement assessment');

        $days = collect($items)->pluck('scheduled_date')->unique()->map(fn ($d) => (int) date('w', strtotime($d)))->all();
        $this->assertEmpty(array_diff($days, [1, 3, 5]), 'items are only scheduled on the chosen study days');
        foreach (collect($items)->groupBy('scheduled_date') as $dayItems) {
            $this->assertSame(20, $dayItems->sum('estimated_minutes'), 'each study day fills the daily time budget exactly');
        }
    }

    public function test_one_profile_per_target_language_and_multiple_languages_allowed(): void
    {
        $user = $this->actingAsUser($this->makeUser());
        $this->subscribe($user, 'premium_monthly'); // Free includes one language
        $payload = ['target_language' => 'de', 'purpose' => 'travel', 'daily_minutes' => 15];

        $this->postJson('/api/v1/learner-profiles', $payload)->assertCreated();
        $this->postJson('/api/v1/learner-profiles', $payload)->assertStatus(409)->assertJsonPath('code', 'profile_exists');
        $this->postJson('/api/v1/learner-profiles', ['target_language' => 'it'] + $payload)->assertCreated();

        $this->assertCount(2, $this->getJson('/api/v1/learner-profiles')->json('data'));
        $this->assertSame(2, $user->learnerProfiles()->count());
    }

    public function test_onboarding_validation(): void
    {
        $this->actingAsUser($this->makeUser());

        $this->postJson('/api/v1/learner-profiles', [
            'target_language' => 'xx', 'purpose' => 'nope', 'daily_minutes' => 2,
            'schedule' => [['day' => 9]], 'preferences' => ['hack' => 'x'],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'target_language', 'purpose', 'daily_minutes', 'schedule.0.day', 'preferences.hack',
        ]);
    }

    public function test_learners_cannot_access_other_learners_profiles(): void
    {
        $profile = $this->makeLearner('es');
        $this->actingAsUser($this->makeUser());

        $this->getJson("/api/v1/learner-profiles/{$profile->public_id}")->assertForbidden();
        $this->getJson("/api/v1/learner-profiles/{$profile->public_id}/dashboard")->assertForbidden();
        $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/assessments")->assertForbidden();
        $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/plan/regenerate")->assertForbidden();
    }

    public function test_changing_study_settings_creates_a_new_plan_version(): void
    {
        $profile = $this->makeLearner('es');
        $this->actingAsUser($profile->user);

        $this->patchJson("/api/v1/learner-profiles/{$profile->public_id}", ['daily_minutes' => 45])->assertOk()
            ->assertJsonPath('data.daily_minutes', 45);

        $versions = $this->getJson("/api/v1/learner-profiles/{$profile->public_id}/plan/versions")->assertOk()->json('data');
        $this->assertCount(2, $versions);
        $this->assertSame('settings_change', $versions[0]['trigger']);
        $this->assertSame(45, $versions[0]['daily_minutes']);
        $this->assertSame(30, $versions[1]['daily_minutes'], 'earlier versions are preserved unchanged');
    }

    public function test_archiving_a_language(): void
    {
        $profile = $this->makeLearner('es');
        $this->actingAsUser($profile->user);

        $this->deleteJson("/api/v1/learner-profiles/{$profile->public_id}")->assertOk();
        $this->getJson('/api/v1/learner-profiles')->assertOk()->assertJsonCount(0, 'data');
        // Re-enrolling in the same language later is allowed.
        $this->postJson('/api/v1/learner-profiles', ['target_language' => 'es', 'purpose' => 'general', 'daily_minutes' => 10])->assertCreated();
    }
}
