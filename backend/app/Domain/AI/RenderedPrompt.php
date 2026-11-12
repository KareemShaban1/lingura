<?php

namespace App\Domain\AI;

final readonly class RenderedPrompt
{
    public function __construct(public string $text, public ?array $schema, public string $versionTag) {}
}
