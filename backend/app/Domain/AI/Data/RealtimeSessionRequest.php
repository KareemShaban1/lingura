<?php

namespace App\Domain\AI\Data;

final readonly class RealtimeSessionRequest
{
    public function __construct(
        public string $instructions,
        public string $languageCode,
        public ?string $voice = null,
        public ?int $userId = null,
    ) {}
}
