<?php

namespace App\Domain\AI\Exceptions;

class CapabilityUnavailable extends AiException
{
    public static function for(string $feature, string $languageCode): self
    {
        return new self("The {$feature} capability is not available for language {$languageCode}.", 'capability_unavailable');
    }
}
