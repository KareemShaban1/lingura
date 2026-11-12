<?php

namespace App\Domain\AI\Exceptions;

class ProviderNotConfigured extends AiException
{
    public static function make(string $provider): self
    {
        return new self("AI provider [{$provider}] is not configured.", 'provider_not_configured');
    }
}
