<?php

namespace Tests\Feature;

use App\Jobs\SynthesizeItemAudio;
use App\Models\AssessmentAnswer;
use App\Models\AssessmentItem;
use App\Models\AssessmentItemOption;
use App\Models\LearnerProfile;
use App\Models\SkillScore;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssessmentFlowTest extends TestCase
{
    /**
     * Drives the adaptive test through the API. $policy decides per item whether to answer correctly.
     *
     * @param  callable(AssessmentItem): bool  $policy
     */
    private function runAssessment(LearnerProfile $profile, callable $policy): string
    {
        $attemptId = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/assessments")->assertCreated()->json('data.id');

        for ($guard = 0; $guard < 100; $guard++) {
            $next = $this->getJson("/api/v1/assessment-attempts/{$attemptId}/next")->assertOk();
            if ($next->json('done')) {
                return $attemptId;
            }
            $item = AssessmentItem::where('public_id', $next->json('item.id'))->firstOrFail();
            $correct = $policy($item);
            $option = AssessmentItemOption::where('assessment_item_id', $item->id)->where('is_correct', $correct)->first();

            $this->postJson("/api/v1/assessment-attempts/{$attemptId}/answers", [
                'item_id' => $item->public_id, 'option_id' => $option->id, 'response_time_ms' => 4200,
            ])->assertOk()->assertExactJson(['accepted' => true]);
        }
        $this->fail('Assessment did not terminate.');
    }

    public function test_item_payload_never_exposes_the_answer_key(): void
    {
        $profile = $this->makeLearner('en');
        $this->actingAsUser($profile->user);
        $attemptId = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/assessments")->json('data.id');

        $item = $this->getJson("/api/v1/assessment-attempts/{$attemptId}/next")->assertOk()->json('item');

        $this->assertArrayNotHasKey('is_correct', $item['options'][0]);
        $this->assertArrayNotHasKey('difficulty', $item);
        $this->assertArrayNotHasKey('explanation', $item);
        $this->assertSame('grammar', $item['skill'], 'sections run in configured order');
    }

    public function test_strong_learner_places_high_and_weak_learner_places_low_per_skill(): void
    {
        $strong = $this->makeLearner('es');
        $this->actingAsUser($strong->user);
        $strongId = $this->runAssessment($strong, fn () => true);
        $strongReport = $this->getJson("/api/v1/assessment-attempts/{$strongId}/report")->assertOk()->json('data');

        $weak = $this->makeLearner('es');
        $this->actingAsUser($weak->user);
        $weakId = $this->runAssessment($weak, fn () => false);
        $weakReport = $this->getJson("/api/v1/assessment-attempts/{$weakId}/report")->assertOk()->json('data');

        $this->assertGreaterThanOrEqual(4, $strongReport['overall']['level']['ordinal']);
        $this->assertLessThanOrEqual(1, $weakReport['overall']['level']['ordinal']);
        $this->assertFalse($strongReport['is_official']);
        $this->assertSame('lower_median_of_assessed_skills', $strongReport['overall']['method']);
    }

    public function test_skills_are_estimated_independently_and_overall_is_not_an_average(): void
    {
        $profile = $this->makeLearner('de');
        $this->actingAsUser($profile->user);

        // Excellent grammar, weak vocabulary, middling reading.
        $attemptId = $this->runAssessment($profile, function (AssessmentItem $item) {
            return match ($item->skill->slug) {
                'grammar' => true,
                'vocabulary' => false,
                default => $item->level->ordinal <= 3,
            };
        });
        $report = $this->getJson("/api/v1/assessment-attempts/{$attemptId}/report")->json('data');
        $skills = collect($report['skills'])->keyBy('skill');

        $this->assertSame('completed', $skills['grammar']['status']);
        $this->assertSame('completed', $skills['vocabulary']['status']);
        $this->assertGreaterThan($skills['vocabulary']['level']['ordinal'], $skills['grammar']['level']['ordinal']);

        $ordinals = collect(['grammar', 'vocabulary', 'reading'])->map(fn ($s) => $skills[$s]['level']['ordinal'])->sort()->values();
        $this->assertSame($ordinals[1], $report['overall']['level']['ordinal'], 'overall = lower median of assessed skills');

        $codes = collect($report['findings'])->pluck('code');
        $this->assertTrue($codes->contains('skill_below_overall') || $codes->contains('skill_above_overall'));

        // Productive skills are honestly reported as not yet assessed, and the overall result is provisional.
        $this->assertSame('not_available', $skills['speaking']['status']);
        $this->assertSame('coming_in_future_release', $skills['writing']['reason']);
        $this->assertTrue($report['overall']['is_provisional']);
        $this->assertContains('productive_skills_not_assessed', $report['overall']['provisional_reasons']);

        // Skill profiles + append-only history were written for the assessed skills only.
        $profile->refresh();
        $this->assertSame(3, $profile->skillProfiles()->where('status', 'assessed')->count());
        $this->assertSame(3, SkillScore::count());
        $this->assertSame(LearnerProfile::STATUS_ACTIVE, $profile->status);
    }

    public function test_listening_is_not_available_without_audio_and_available_after_tts(): void
    {
        $profile = $this->makeLearner('it');
        $this->actingAsUser($profile->user);
        $attempt = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/assessments")->json('data');
        $listening = collect($attempt['sections'])->firstWhere('skill', 'listening');
        $this->assertSame('not_available', $listening['status']);
        $this->assertSame('no_content_available', $listening['reason']);

        // Generate audio with the (fake) TTS provider, then a new attempt includes listening.
        Storage::fake('local');
        AssessmentItem::where('format', 'listening_mcq')->whereHas('language', fn ($q) => $q->where('code', 'it'))
            ->pluck('id')->each(fn ($id) => SynthesizeItemAudio::dispatchSync($id));
        $this->assertSame(7, AssessmentItem::whereNotNull('audio_path')->count());
        $this->assertDatabaseHas('ai_usage_records', ['operation' => 'tts.assessment_item', 'status' => 'success']);

        $this->postJson("/api/v1/assessment-attempts/{$attempt['id']}/complete")->assertOk();
        $second = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/assessments")->assertCreated()->json('data');
        $this->assertSame('pending', collect($second['sections'])->firstWhere('skill', 'listening')['status']);

        foreach (['grammar', 'vocabulary', 'reading'] as $skill) {
            $this->postJson("/api/v1/assessment-attempts/{$second['id']}/sections/{$skill}/skip")->assertOk();
        }
        $item = $this->getJson("/api/v1/assessment-attempts/{$second['id']}/next")->json('item');
        $this->assertSame('listening', $item['skill']);
        $this->assertTrue($item['has_audio']);
        $this->assertNull($item['stimulus'], 'the listening transcript is withheld');

        $url = $this->getJson("/api/v1/assessment-attempts/{$second['id']}/items/{$item['id']}/audio")->assertOk()->json('url');
        $this->assertStringContainsString('signature=', $url);
        $this->get($url)->assertOk();
        $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $url))->assertForbidden();
    }

    public function test_answers_are_idempotent_and_only_the_current_item_can_be_answered(): void
    {
        $profile = $this->makeLearner('en');
        $this->actingAsUser($profile->user);
        $attemptId = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/assessments")->json('data.id');

        $item = $this->getJson("/api/v1/assessment-attempts/{$attemptId}/next")->json('item');
        $again = $this->getJson("/api/v1/assessment-attempts/{$attemptId}/next")->json('item');
        $this->assertSame($item['id'], $again['id'], 'refreshing serves the same item');

        $other = AssessmentItem::where('public_id', '!=', $item['id'])->published()->first();
        $this->postJson("/api/v1/assessment-attempts/{$attemptId}/answers", [
            'item_id' => $other->public_id, 'option_id' => $other->options()->first()->id,
        ])->assertStatus(409)->assertJsonPath('code', 'item_not_current');

        $this->postJson("/api/v1/assessment-attempts/{$attemptId}/answers", [
            'item_id' => $item['id'], 'option_id' => 999999,
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_option');

        $payload = ['item_id' => $item['id'], 'option_id' => $item['options'][0]['id']];
        $this->postJson("/api/v1/assessment-attempts/{$attemptId}/answers", $payload)->assertOk();
        $this->postJson("/api/v1/assessment-attempts/{$attemptId}/answers", $payload)->assertOk();
        $this->assertSame(1, AssessmentAnswer::count());
    }

    public function test_starting_twice_resumes_the_same_attempt_and_report_requires_completion(): void
    {
        $profile = $this->makeLearner('ar');
        $this->actingAsUser($profile->user);

        $first = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/assessments")->assertCreated()->json('data.id');
        $second = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/assessments")->assertOk()->json('data.id');
        $this->assertSame($first, $second);
        $this->assertSame('rtl', $this->getJson("/api/v1/assessment-attempts/{$first}")->json('data.target_language.direction'));

        $this->getJson("/api/v1/assessment-attempts/{$first}/report")->assertStatus(409);
    }

    public function test_completing_an_assessment_creates_a_new_explainable_plan_version(): void
    {
        $profile = $this->makeLearner('fr', ['purpose' => 'academic', 'focus_skills' => ['reading']]);
        $this->actingAsUser($profile->user);
        $this->runAssessment($profile, fn (AssessmentItem $i) => $i->skill->slug !== 'vocabulary' || $i->level->ordinal <= 1);

        $plan = $this->getJson("/api/v1/learner-profiles/{$profile->public_id}/plan")->assertOk()->json('data');
        $this->assertSame(2, $plan['current_version']['version_number']);
        $this->assertSame('assessment', $plan['current_version']['trigger']);
        $this->assertStringContainsString('placement results', $plan['current_version']['rationale']);

        $vocabulary = collect($plan['current_version']['allocations'])->firstWhere('skill', 'vocabulary');
        $this->assertSame('weak_relative_to_profile', $vocabulary['reason_code']);
        $this->assertNotEmpty($vocabulary['reason']);

        $items = $this->getJson("/api/v1/learner-profiles/{$profile->public_id}/plan/items")->json('data');
        $this->assertNotContains('placement_assessment', array_column($items, 'activity_type'));
        $this->assertContains('revision', array_column($items, 'activity_type'), 'spaced revision is scheduled');
    }

    public function test_ending_early_scores_sections_with_enough_evidence_only(): void
    {
        $profile = $this->makeLearner('es');
        $this->actingAsUser($profile->user);
        $attemptId = $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/assessments")->json('data.id');

        for ($i = 0; $i < 5; $i++) { // exactly min_items for grammar
            $item = $this->getJson("/api/v1/assessment-attempts/{$attemptId}/next")->json('item');
            $this->postJson("/api/v1/assessment-attempts/{$attemptId}/answers", ['item_id' => $item['id'], 'option_id' => $item['options'][0]['id']]);
        }
        $report = $this->postJson("/api/v1/assessment-attempts/{$attemptId}/complete")->assertOk()->json('data');
        $skills = collect($report['skills'])->keyBy('skill');

        $this->assertSame('completed', $skills['grammar']['status']);
        $this->assertSame('skipped', $skills['vocabulary']['status']);
        $this->assertSame('ended_early', $skills['vocabulary']['reason']);
        $this->assertContains('fewer_than_four_skills_assessed', $report['overall']['provisional_reasons']);

        $this->getJson("/api/v1/assessment-attempts/{$attemptId}/next")->assertOk()->assertJsonPath('done', true);
        $this->postJson("/api/v1/assessment-attempts/{$attemptId}/complete")->assertStatus(409);
    }
}
