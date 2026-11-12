<?php

namespace Database\Seeders;

use App\Domain\AI\PromptRegistry;
use App\Models\AiPrompt;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Seeds prompt versions from database/data/prompts.php. Existing versions are immutable:
 * if a stored version's text differs from the file, seeding stops (add a new version instead).
 * The highest version in the file becomes the active one.
 */
class PromptSeeder extends Seeder
{
    public function run(PromptRegistry $registry): void
    {
        foreach (require database_path('data/prompts.php') as $key => $definition) {
            $prompt = AiPrompt::updateOrCreate(['key' => $key], ['description' => $definition['description']]);
            $latest = max(array_keys($definition['versions']));

            foreach ($definition['versions'] as $number => $v) {
                $existing = $prompt->versions()->where('version', $number)->first();
                if ($existing && $existing->template !== $v['template']) {
                    throw new RuntimeException("Prompt {$key} v{$number} was edited after release; add a new version instead.");
                }
                $prompt->versions()->updateOrCreate(['version' => $number], [
                    'template' => $v['template'],
                    'json_schema' => $v['schema'] ?? null,
                    'status' => $number === $latest ? 'active' : 'retired',
                ]);
            }
            $registry->flush($key);
        }
    }
}
