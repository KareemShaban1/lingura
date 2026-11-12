<?php

namespace App\Domain\AI\Data;

final readonly class AudioResult
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $extension,
        public string $model,
        public int $characters,
    ) {}
}
