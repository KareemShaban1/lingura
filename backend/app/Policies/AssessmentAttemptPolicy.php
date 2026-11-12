<?php

namespace App\Policies;

use App\Models\AssessmentAttempt;
use App\Models\User;

class AssessmentAttemptPolicy
{
    public function view(User $user, AssessmentAttempt $attempt): bool
    {
        return $attempt->learnerProfile?->user_id === $user->id;
    }

    public function update(User $user, AssessmentAttempt $attempt): bool
    {
        return $this->view($user, $attempt);
    }
}
