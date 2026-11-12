<?php

namespace App\Domain\AI\Data;

final readonly class TranscriptionRequest
{
    public function __construct(
        public string $operation,
        public string $audioPath,
        public string $languageCode,
        public ?int $durationSeconds = null,
        public ?int $userId = null,
    ) {}
}
