<?php

namespace App\Domain\AI\Data;

final readonly class TextRequest
{
    /**
     * @param  string  $operation  metering key, e.g. "assessment.item_generation"
     * @param  array<string,mixed>|null  $jsonSchema  when set, the provider must return JSON matching this schema
     */
    public function __construct(
        public string $operation,
        public string $instructions,
        public string $input,
        public ?string $languageCode = null,
        public ?array $jsonSchema = null,
        public string $schemaName = 'result',
        public ?int $maxOutputTokens = 2000,
        public ?int $userId = null,
        public ?int $learnerProfileId = null,
        /** @var list<array{role: 'user'|'assistant', content: string}>|null Prior turns for multi-turn calls; `input` is the newest user turn. */
        public ?array $history = null,
        public ?float $temperature = null,
    ) {}
}
