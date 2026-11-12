<?php

namespace App\Domain\AI\Data;

use DateTimeImmutable;

/** A short-lived credential the browser may use to connect directly to a realtime voice provider. */
final readonly class EphemeralSession
{
    public function __construct(
        public string $clientSecret,
        public DateTimeImmutable $expiresAt,
        public string $model,
        public string $provider,
    ) {}
}
