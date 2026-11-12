<?php

namespace App\Domain\AI\Exceptions;

use RuntimeException;

class AiException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'ai_error', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
