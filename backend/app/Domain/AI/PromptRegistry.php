<?php

namespace App\Domain\AI;

use App\Models\AiPrompt;
use App\Models\AiPromptVersion;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Versioned prompt templates. Services ask for a prompt by key; the active version is used and its
 * identifier ("key@vN") is stored on every AI output so results stay reproducible and auditable.
 */
class PromptRegistry
{
    public function active(string $key): AiPromptVersion
    {
        return Cache::remember("ai-prompt:{$key}", now()->addMinutes(5), function () use ($key) {
            $prompt = AiPrompt::where('key', $key)->first() ?? throw new RuntimeException("Unknown prompt [{$key}].");

            return $prompt->versions()->where('status', 'active')->first()
                ?? throw new RuntimeException("Prompt [{$key}] has no active version.");
        });
    }

    /** @param array<string, string|int> $variables */
    public function render(string $key, array $variables): RenderedPrompt
    {
        $version = $this->active($key);
        $text = preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($m) use ($variables, $key) {
            if (! array_key_exists($m[1], $variables)) {
                throw new RuntimeException("Prompt [{$key}] needs variable [{$m[1]}].");
            }

            return (string) $variables[$m[1]];
        }, $version->template);

        return new RenderedPrompt($text, $version->json_schema, "{$key}@v{$version->version}");
    }

    public function flush(string $key): void
    {
        Cache::forget("ai-prompt:{$key}");
    }
}
