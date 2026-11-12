<?php

namespace Tests\Unit;

use App\Domain\AI\PromptRegistry;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Regression: prompts must survive a serializing cache store (Redis/file), not just the array store. */
class PromptRegistryCacheTest extends TestCase
{
    public function test_cached_prompt_is_plain_data_and_rehydrates(): void
    {
        config(['cache.default' => 'file']);
        Cache::forget('ai-prompt:tutor.turn');
        $registry = app(PromptRegistry::class);

        $first = $registry->render('tutor.turn', $vars = [
            'tutor_name' => 'Lina', 'tutor_persona' => 'patient teacher', 'language' => 'Spanish', 'scenario' => 'café',
            'level' => 'A1', 'correction_mode' => 'gentle', 'explanation_language' => 'Arabic', 'topic_slugs' => 'gustar',
        ]);
        $cached = Cache::get('ai-prompt:tutor.turn');
        $this->assertIsArray($cached, 'only scalar attributes are cached');

        $second = $registry->render('tutor.turn', $vars); // served from the serialized cache
        $this->assertSame($first->text, $second->text);
        $this->assertSame('object', $second->schema['type']);
        $this->assertSame('tutor.turn@v1', $second->versionTag);
        Cache::forget('ai-prompt:tutor.turn');
    }
}
