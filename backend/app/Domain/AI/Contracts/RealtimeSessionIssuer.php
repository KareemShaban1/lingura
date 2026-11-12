<?php

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Data\EphemeralSession;
use App\Domain\AI\Data\RealtimeSessionRequest;

interface RealtimeSessionIssuer
{
    public function issue(RealtimeSessionRequest $request): EphemeralSession;
}
