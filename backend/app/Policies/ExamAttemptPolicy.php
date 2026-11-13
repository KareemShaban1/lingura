<?php

namespace App\Policies;

use App\Models\ExamAttempt;
use App\Models\User;

class ExamAttemptPolicy
{
    public function view(User $user, ExamAttempt $attempt): bool
    {
        return $attempt->learnerProfile?->user_id === $user->id;
    }

    public function update(User $user, ExamAttempt $attempt): bool
    {
        return $this->view($user, $attempt);
    }
}
