<?php

namespace App\Domain\AI\Data;

final readonly class TextResult
{
    public function __construct(
        public string $text,
        public ?array $json,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
    ) {}
}
