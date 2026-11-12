<?php

namespace App\Domain\Shared;

use RuntimeException;

/** A business-rule violation rendered as `{message, code}` with the given HTTP status. */
class DomainError extends RuntimeException
{
    /** @param array<string, mixed> $context extra machine-readable details (e.g. which quota, when it resets) */
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 409, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
