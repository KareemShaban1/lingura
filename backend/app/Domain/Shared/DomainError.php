<?php

namespace App\Domain\Shared;

use RuntimeException;

/** A business-rule violation rendered as `{message, code}` with the given HTTP status. */
class DomainError extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
