<?php

namespace Tests\Feature;

use App\Domain\AI\Drivers\FakeDriver;
use App\Domain\AI\PromptRegistry;
use App\Models\AiProvider;
use App\Models\AssessmentItem;
use App\Models\AuditLog;
use App\Models\Exercise;
use App\Models\LanguageCapability;
use App\Models\Role;
use App\Models\User;
use App\Models\WritingEvaluation;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminTest extends TestCase
{
    private function staff(string $role): User
    {
        $user = $this->makeUser();
        $user->roles()->sync([Role::where('slug', $role)->value('id')]);

        return $this->actingAsUser($user->fresh());
    }

    public function test_rbac_hides_admin_areas_by_permission(): void
    {
        $this->actingAsUser($this->makeUser());
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.is_staff', false)->assertJsonPath('data.permissions', []);
        $this->getJson('/api/v1/admin/users')->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->staff('content_editor');
        $permissions = $this->getJson('/api/v1/auth/me')->json('data.permissions');
        $this->assertContains('content.review', $permissions);
        $this->getJson('/api/v1/admin/review/items')->assertOk();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/billing/plans')->assertForbidden();

        $this->staff('support');
        $this->getJson('/api/v1/admin/users')->assertOk();
        $this->getJson('/api/v1/admin/audit-logs')->assertOk();
        $this->postJson('/api/v1/admin/users/1/grants', [])->assertForbidden(); // grants also need billing.manage
    }

    public function test_user_management_is_audited_and_privilege_escalation_is_blocked(): void
    {
        $target = $this->makeUser(['email' => 'target@example.test']);
        $target->createToken('phone');
        $support = $this->staff('support');

        $this->getJson('/api/v1/admin/users?q=target@')->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchJson("/api/v1/admin/users/{$target->id}/status", ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertSame(0, $target->tokens()->count(), 'suspension revokes API tokens');
        $this->assertTrue(AuditLog::where('action', 'admin.user_status')->where('actor_user_id', $support->id)->exists());
        $this->patchJson("/api/v1/admin/users/{$support->id}/status", ['status' => 'suspended'])->assertStatus(422);

        $this->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['admin']])->assertForbidden();
        $this->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['content_editor']])->assertOk()->assertJsonPath('data.roles', ['content_editor']);

        $this->staff('super_admin');
        $this->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['admin']])->assertOk();
        $this->postJson("/api/v1/admin/users/{$target->id}/grants", ['plan' => 'pro_monthly', 'until' => now()->addMonth()->toDateString(), 'note' => 'Partner'])
            ->assertCreated()->assertJsonPath('data.provider', 'manual');
        $this->getJson("/api/v1/admin/users/{$target->id}")->assertOk()->assertJsonPath('data.plan.slug', 'pro_monthly');
    }

    public function test_review_queue_publishes_drafts_and_calibrates_ai_scores(): void
    {
        FakeDriver::queueText(['items' => [
            ['stimulus' => '', 'prompt' => 'Vosotros ___ en Sevilla.', 'options' => ['vivís', 'vive', 'vivo', 'viven'], 'correct_index' => 0, 'topic' => 'present_regular', 'explanation' => ''],
        ]]);
        $this->artisan('lf:items:generate', ['language' => 'es', 'skill' => 'grammar', 'level' => 'a1', '--count' => 1])->assertSuccessful();
        $editor = $this->staff('content_editor');

        $queue = $this->getJson('/api/v1/admin/review/items')->assertOk()->json('data');
        $this->assertCount(1, $queue);
        $this->assertTrue(collect($queue[0]['options'])->contains(fn ($o) => $o['is_correct'] && $o['label'] === 'vivís'));
        $this->postJson("/api/v1/admin/review/items/{$queue[0]['id']}", ['decision' => 'approved'])->assertOk();
        $item = AssessmentItem::where('public_id', $queue[0]['id'])->firstOrFail();
        $this->assertSame('published', $item->review_status);
        $this->assertSame($editor->id, $item->reviewed_by);
        $this->postJson("/api/v1/admin/review/items/{$item->public_id}", ['decision' => 'approved'])->assertStatus(409);
        $this->assertDatabaseHas('content_reviews', ['reviewable_id' => $item->id, 'decision' => 'approved']);

        // Writing evaluation flagged for calibration → human score recorded, agreement reported.
        $profile = $this->makeLearner('fr');
        $this->actingAsUser($profile->user);
        $task = collect($this->getJson("/api/v1/learner-profiles/{$profile->public_id}/writing-tasks")->json('data'))->firstWhere('type', 'email');
        $text = "Salut Marc,\nJe t'invite à ma fête samedi soir chez moi. On va manger une pizza et danser. Tu peux venir ?\nLéa";
        FakeDriver::queueText([
            'criteria' => collect(['task_achievement', 'grammar', 'vocabulary', 'coherence', 'organization'])->mapWithKeys(fn ($c) => [$c => ['score' => 4, 'comment' => 'Bien.']])->all(),
            'estimated_level' => 'C1', 'corrections' => [], 'corrected_text' => $text, 'summary' => 'Clair.',
        ]);
        $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/writing-tasks/{$task['id']}/submissions", ['text' => $text])->assertStatus(202);

        $this->actingAsUser($editor);
        $pending = $this->getJson('/api/v1/admin/review/evaluations')->assertOk()->json('data');
        $this->assertCount(1, $pending);
        $this->assertArrayNotHasKey('learner', $pending[0], 'reviewers do not see who wrote it');
        $calibration = $this->postJson("/api/v1/admin/review/evaluations/{$pending[0]['id']}", ['overall' => 3.5, 'notes' => 'Slightly generous.'])
            ->assertOk()->json('meta.calibration');
        $this->assertSame(0.5, $calibration[0]['mean_absolute_error']);
        $this->assertEquals(1.0, $calibration[0]['within_half_band']);
        $this->assertSame(3.5, WritingEvaluation::first()->human_overall_score);
    }

    public function test_prompt_versions_are_drafted_then_activated(): void
    {
        $this->staff('admin');
        $prompts = collect($this->getJson('/api/v1/admin/ai/prompts')->assertOk()->json('data'));
        $active = collect($prompts->firstWhere('key', 'writing.evaluate')['versions'])->firstWhere('status', 'active');

        $this->postJson('/api/v1/admin/ai/prompts/writing.evaluate/versions', ['template' => 'Evaluate this text, please, carefully.'])
            ->assertStatus(422)->assertJsonPath('code', 'template_variables_missing');

        $draft = $this->postJson('/api/v1/admin/ai/prompts/writing.evaluate/versions', ['template' => $active['template']."\nBe concise."])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $this->assertSame('writing.evaluate@v1', app(PromptRegistry::class)->render('writing.evaluate', array_fill_keys(['language', 'level', 'task', 'min_words', 'max_words', 'stats', 'explanation_language', 'levels'], 'x'))->versionTag);

        $this->postJson("/api/v1/admin/ai/prompt-versions/{$draft['id']}/activate")->assertOk();
        $this->assertSame('writing.evaluate@v2', app(PromptRegistry::class)->render('writing.evaluate', array_fill_keys(['language', 'level', 'task', 'min_words', 'max_words', 'stats', 'explanation_language', 'levels'], 'x'))->versionTag, 'cache flushed on activation');
    }

    public function test_ai_kill_switch_and_capability_matrix(): void
    {
        $this->staff('admin');
        $provider = AiProvider::where('slug', 'fake')->firstOrFail();
        $this->patchJson("/api/v1/admin/ai/providers/{$provider->id}", ['is_enabled' => false])->assertOk();

        $profile = $this->makeLearner('es');
        $this->actingAsUser($profile->user);
        $this->postJson("/api/v1/learner-profiles/{$profile->public_id}/conversations", ['tutor' => 'lina', 'mode' => 'text'])->assertStatus(503);

        $this->staff('admin');
        $capability = LanguageCapability::whereHas('language', fn ($q) => $q->where('code', 'es'))->where('feature', 'pronunciation')->firstOrFail();
        $languages = $this->patchJson("/api/v1/admin/language-capabilities/{$capability->id}", ['status' => 'unsupported'])->assertOk()->json('data');
        $this->assertSame('unsupported', collect(collect($languages)->firstWhere('code', 'es')['capabilities'])->firstWhere('feature', 'pronunciation')['status']);
        $this->assertTrue(AuditLog::where('action', 'admin.capability_updated')->exists());
    }

    public function test_content_import_dry_run_and_exercise_retirement(): void
    {
        $this->staff('content_editor');
        $pack = json_decode(file_get_contents(database_path('data/content/es.json')), true);
        $pack['exercises'][0]['questions'][0]['p'] .= ' (edited)';
        $file = UploadedFile::fake()->createWithContent('es.json', json_encode($pack));

        // Dry run validates without writing anything.
        $this->post('/api/v1/admin/content/import', ['pack' => $file, 'dry_run' => '1'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.dry_run', true);
        $this->assertStringNotContainsString('(edited)', json_encode(Exercise::where('key', $pack['exercises'][0]['key'])->first()->questions()->pluck('prompt')));

        $broken = UploadedFile::fake()->createWithContent('bad.json', json_encode(['language' => 'es', 'exercises' => [['key' => 'x', 'type' => 'nope']]]));
        $this->post('/api/v1/admin/content/import', ['pack' => $broken, 'dry_run' => '0'], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'invalid_pack');

        $exercise = Exercise::where('key', 'es-a1-order')->firstOrFail();
        $this->patchJson("/api/v1/admin/content/exercises/{$exercise->public_id}", ['status' => 'retired'])->assertOk();
        $this->artisan('db:seed', ['--class' => 'ContentSeeder'])->assertSuccessful();
        $this->assertSame('retired', $exercise->fresh()->review_status, 're-imports keep an editor\'s retirement');
    }

    public function test_analytics_and_plan_administration(): void
    {
        $this->subscribe($this->makeUser(), 'premium_yearly');
        $this->staff('admin');

        $data = $this->getJson('/api/v1/admin/analytics/overview')->assertOk()->json('data');
        $this->assertSame(1, $data['subscriptions']['complimentary']);
        $this->assertSame(0, $data['subscriptions']['mrr_minor'], 'complimentary grants are not revenue');
        $this->assertCount(8, $data['retention']);

        $plan = collect($this->getJson('/api/v1/admin/billing/plans')->assertOk()->json('data'))->firstWhere('slug', 'free');
        $this->patchJson("/api/v1/admin/billing/plans/{$plan['id']}", ['limits' => ['tutor_messages' => 20, 'unknown_feature' => 5]])->assertOk();
        $this->assertSame(20, collect($this->getJson('/api/v1/billing/plans')->json('data'))->firstWhere('slug', 'free')['limits']['tutor_messages']);
        $this->assertDatabaseMissing('plan_entitlements', ['feature' => 'unknown_feature']);
    }
}
