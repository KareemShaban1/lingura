<?php

namespace App\Events;

use App\Models\AssessmentAttempt;
use Illuminate\Foundation\Events\Dispatchable;

class AssessmentCompleted
{
    use Dispatchable;

    public function __construct(public readonly AssessmentAttempt $attempt) {}
}
