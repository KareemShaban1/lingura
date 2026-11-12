<?php

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Data\TextRequest;
use App\Domain\AI\Data\TextResult;

interface TextGenerator
{
    public function generate(TextRequest $request): TextResult;
}
