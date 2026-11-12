<?php

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Data\AudioResult;
use App\Domain\AI\Data\SpeechRequest;

interface SpeechSynthesizer
{
    public function synthesize(SpeechRequest $request): AudioResult;
}
