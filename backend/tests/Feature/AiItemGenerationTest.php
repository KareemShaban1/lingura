<?php

namespace Tests\Feature;

use App\Domain\AI\Drivers\FakeDriver;
use App\Domain\AI\Exceptions\AiException;
use App\Domain\AI\Exceptions\CapabilityUnavailable;
use App\Domain\Assessment\Items\ItemGenerator;
use App\Domain\Languages\CapabilityMatrix;
use App\Models\AssessmentItem;
use App\Models\CefrLevel;
use App\Models\Language;
use App\Models\LanguageCapability;
use App\Models\Role;
use App\Models\Skill;
use Tests\TestCase;

class AiItemGenerationTest extends TestCase
{
    public function test_generated_items_are_validated_and_stored_as_drafts_only(): void
    {
        FakeDriver::queueText(['items' => [
            ['stimulus' => '', 'prompt' => 'Nosotros ___ café cada mañana.', 'options' => ['tomamos', 'toma', 'tomo', 'toman'], 'correct_index' => 0, 'topic' => 'present_regular', 'explanation' => 'First person plural.'],
            ['stimulus' => '', 'prompt' => 'Sin hueco aquí.', 'options' => ['a', 'b', 'c', 'd'], 'correct_index' => 0, 'topic' => 'x', 'explanation' => ''],
            ['stimulus' => '', 'prompt' => 'Ella ___ alta.', 'options' => ['es', 'es', 'son', 'eres'], 'correct_index' => 0, 'topic' => 'ser', 'explanation' => ''],
        ]]);

        $result = app(ItemGenerator::class)->generate(
            Language::where('code', 'es')->first(), Skill::where('slug', 'grammar')->first(), CefrLevel::where('code', 'A1')->first(), 3,
        );

        $this->assertCount(1, $result['created']);
        $this->assertCount(2, $result['rejected']);
        $draft = $result['created'][0];
        $this->assertSame(AssessmentItem::STATUS_DRAFT, $draft->review_status);
        $this->assertSame('ai_generated', $draft->source);
        $this->assertSame(1, $draft->options()->where('is_correct', true)->count());
        $this->assertDatabaseHas('ai_usage_records', ['operation' => 'assessment.item_generation', 'provider_slug' => 'fake', 'status' => 'success']);

        // The prompt sent to the provider requested structured output.
        $this->assertNotNull(FakeDriver::$requests[0]->jsonSchema);
    }

    public function test_drafts_are_never_served_and_publishing_requires_a_reviewer(): void
    {
        FakeDriver::queueText(['items' => [
            ['stimulus' => '', 'prompt' => 'Vosotros ___ en Sevilla.', 'options' => ['vivís', 'vive', 'vivo', 'viven'], 'correct_index' => 0, 'topic' => 'present_regular', 'explanation' => ''],
        ]]);
        $this->artisan('lf:items:generate', ['language' => 'es', 'skill' => 'grammar', 'level' => 'a1', '--count' => 1])->assertSuccessful();
        $draft = AssessmentItem::where('review_status', 'draft')->firstOrFail();

        $student = $this->makeUser();
        $this->artisan('lf:items:publish', ['ids' => [$draft->public_id], '--reviewer' => $student->email])->assertFailed();

        $editor = $this->makeUser();
        $editor->roles()->attach(Role::where('slug', 'content_editor')->value('id'));
        $this->artisan('lf:items:publish', ['ids' => [$draft->public_id], '--reviewer' => $editor->email])->assertSuccessful();
        $this->assertSame('published', $draft->fresh()->review_status);
        $this->assertSame($editor->id, $draft->fresh()->reviewed_by);
    }

    public function test_capability_matrix_blocks_unsupported_operations(): void
    {
        LanguageCapability::whereHas('language', fn ($q) => $q->where('code', 'ar'))->where('feature', 'content_generation')
            ->update(['status' => 'unsupported']);
        app(CapabilityMatrix::class)->flush();

        $this->expectException(CapabilityUnavailable::class);
        app(ItemGenerator::class)->generate(
            Language::where('code', 'ar')->first(), Skill::where('slug', 'grammar')->first(), CefrLevel::where('code', 'A1')->first(), 1,
        );
    }

    public function test_provider_failures_are_metered_and_normalised(): void
    {
        config(['ai.default_provider' => 'openai', 'ai.providers.openai.api_key' => null]);

        try {
            app(ItemGenerator::class)->generate(
                Language::where('code', 'en')->first(), Skill::where('slug', 'vocabulary')->first(), CefrLevel::where('code', 'B1')->first(), 1,
            );
            $this->fail('Expected an AI exception');
        } catch (AiException $e) {
            $this->assertSame('provider_not_configured', $e->errorCode);
        }
        $this->assertDatabaseHas('ai_usage_records', ['provider_slug' => 'openai', 'status' => 'error', 'error_code' => 'provider_not_configured']);
    }
}
