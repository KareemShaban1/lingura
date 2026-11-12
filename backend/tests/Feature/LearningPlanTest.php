<?php

namespace Tests\Feature;

use App\Models\LearningPlanItem;
use App\Models\LearningSession;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LearningPlanTest extends TestCase
{
    private function firstPracticeItem(string $profileId): array
    {
        return collect($this->getJson("/api/v1/learner-profiles/{$profileId}/plan/items")->json('data'))
            ->firstWhere(fn ($i) => $i['activity_type'] !== 'placement_assessment');
    }

    public function test_session_lifecycle_credits_real_elapsed_time_only(): void
    {
        $profile = $this->makeLearner('es', ['daily_minutes' => 45]);
        $this->actingAsUser($profile->user);
        $item = $this->firstPracticeItem($profile->public_id);

        $this->postJson("/api/v1/plan-items/{$item['id']}/start")->assertCreated();
        $session = LearningSession::latest('id')->first();
        $this->assertSame(LearningPlanItem::IN_PROGRESS, LearningPlanItem::where('public_id', $item['id'])->value('status'));

        // Client claims 2 hours, but only 10 minutes have actually elapsed.
        $this->travel(10)->minutes();
        $this->postJson("/api/v1/learning-sessions/{$session->public_id}/complete", ['active_seconds' => 7200, 'self_rating' => 3])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'completed')
            ->assertJsonPath('data.plan_item.status', 'completed');

        // 600 s elapsed + 60 s clock-skew tolerance (+1 s for second-precision timestamps).
        $this->assertLessThanOrEqual(661, $session->fresh()->active_seconds);
        $this->assertGreaterThanOrEqual(600, $session->fresh()->active_seconds);
        $this->assertDatabaseHas('learning_events', ['event_type' => 'session.completed']);

        // Completing again is a no-op, starting a finished item is rejected.
        $this->postJson("/api/v1/learning-sessions/{$session->public_id}/complete", ['active_seconds' => 60])->assertOk();
        $this->postJson("/api/v1/plan-items/{$item['id']}/start")->assertStatus(409)->assertJsonPath('code', 'item_finished');
    }

    public function test_placement_item_must_use_the_assessment_flow(): void
    {
        $profile = $this->makeLearner('es');
        $this->actingAsUser($profile->user);
        $placement = collect($this->getJson("/api/v1/learner-profiles/{$profile->public_id}/plan/items")->json('data'))
            ->firstWhere('activity_type', 'placement_assessment');

        $this->postJson("/api/v1/plan-items/{$placement['id']}/start")->assertStatus(422)->assertJsonPath('code', 'use_assessment_flow');
    }

    public function test_pause_resume_and_regenerate_keep_version_history(): void
    {
        $profile = $this->makeLearner('it');
        $this->actingAsUser($profile->user);
        $id = $profile->public_id;
        $item = $this->firstPracticeItem($id);

        $this->postJson("/api/v1/learner-profiles/{$id}/plan/pause")->assertOk()->assertJsonPath('data.status', 'paused');
        $this->postJson("/api/v1/plan-items/{$item['id']}/start")->assertStatus(409)->assertJsonPath('code', 'plan_paused');

        $this->postJson("/api/v1/learner-profiles/{$id}/plan/resume")->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.current_version.trigger', 'resume');

        // Items from a superseded version cannot be started.
        $this->postJson("/api/v1/plan-items/{$item['id']}/start")->assertStatus(409)->assertJsonPath('code', 'item_superseded');

        $this->postJson("/api/v1/learner-profiles/{$id}/plan/regenerate")->assertOk()
            ->assertJsonPath('data.current_version.version_number', 3);

        $versions = $this->getJson("/api/v1/learner-profiles/{$id}/plan/versions")->json('data');
        $this->assertSame([3, 2, 1], array_column($versions, 'version_number'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'plan.regenerated']);
    }

    public function test_skip_item(): void
    {
        $profile = $this->makeLearner('de');
        $this->actingAsUser($profile->user);
        $item = $this->firstPracticeItem($profile->public_id);

        $this->postJson("/api/v1/plan-items/{$item['id']}/skip", ['reason' => 'busy'])->assertOk()->assertJsonPath('data.status', 'skipped');
    }

    public function test_dashboard_and_progress_reflect_only_recorded_activity(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $profile = $this->makeLearner('en', ['daily_minutes' => 30]);
        $this->actingAsUser($profile->user);
        $id = $profile->public_id;

        $empty = $this->getJson("/api/v1/learner-profiles/{$id}/dashboard")->assertOk()->json('data');
        $this->assertSame(0, $empty['streak']['current_days']);
        $this->assertEquals(0, $empty['week_minutes']);
        $this->assertCount(7, $empty['week']);

        // Study yesterday and today.
        foreach (['2026-11-11 09:00:00', '2026-11-12 09:00:00'] as $at) {
            LearningSession::create([
                'learner_profile_id' => $profile->id, 'started_at' => Carbon::parse($at),
                'ended_at' => Carbon::parse($at)->addMinutes(15), 'active_seconds' => 900, 'outcome' => 'completed',
            ]);
        }

        $dashboard = $this->getJson("/api/v1/learner-profiles/{$id}/dashboard")->json('data');
        $this->assertSame(2, $dashboard['streak']['current_days']);
        $this->assertTrue($dashboard['streak']['studied_today']);
        $this->assertEquals(30, $dashboard['week_minutes']);
        $this->assertEquals(15, $dashboard['today_minutes_done']);

        $progress = $this->getJson("/api/v1/learner-profiles/{$id}/progress")->assertOk()->json('data');
        $this->assertEquals(30, $progress['total_minutes']);
        $this->assertCount(12, $progress['weekly_activity']);
        $this->assertSame([], $progress['attempts']);
    }

    public function test_plan_text_follows_the_readers_interface_language(): void
    {
        $profile = $this->makeLearner('es');
        $this->actingAsUser($profile->user);
        $id = $profile->public_id;

        $english = $this->getJson("/api/v1/learner-profiles/{$id}/plan/items")->json('data.0');
        $this->assertSame('Take your placement assessment', $english['title']);

        $this->patchJson('/api/v1/me/profile', ['interface_language' => 'ar'])->assertOk();
        $this->actingAsUser($profile->user);

        $arabic = $this->getJson("/api/v1/learner-profiles/{$id}/plan/items")->json('data.0');
        $this->assertSame('أجرِ اختبار تحديد المستوى', $arabic['title']);
        $this->assertSame($english['id'], $arabic['id'], 'same stored item, re-localised on read');

        $rationale = $this->getJson("/api/v1/learner-profiles/{$id}/plan")->json('data.current_version.rationale');
        $this->assertStringContainsString('دقيقة يوميًا', $rationale);
    }
}
