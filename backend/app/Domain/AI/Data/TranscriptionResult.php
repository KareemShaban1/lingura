<?php

namespace App\Domain\AI\Data;

final readonly class TranscriptionResult
{
    public function __construct(public string $text, public string $model, public int $audioSeconds) {}
}
