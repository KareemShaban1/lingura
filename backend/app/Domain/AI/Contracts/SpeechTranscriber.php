<?php

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Data\TranscriptionRequest;
use App\Domain\AI\Data\TranscriptionResult;

interface SpeechTranscriber
{
    public function transcribe(TranscriptionRequest $request): TranscriptionResult;
}
