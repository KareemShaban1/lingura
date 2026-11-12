<?php

namespace App\Domain\AI\Data;

final readonly class SpeechRequest
{
    public function __construct(
        public string $operation,
        public string $text,
        public string $languageCode,
        public ?string $voice = null,
        public ?string $instructions = null,
        public string $format = 'mp3',
        public ?int $userId = null,
    ) {}
}
