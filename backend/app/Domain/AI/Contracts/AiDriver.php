<?php

namespace App\Domain\AI\Contracts;

/** A provider driver implements every operation it supports; unsupported ones throw ProviderOperationUnsupported. */
interface AiDriver extends RealtimeSessionIssuer, SpeechSynthesizer, SpeechTranscriber, TextGenerator
{
    public function slug(): string;

    public function modelFor(string $operation): string;
}
