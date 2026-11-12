<?php

namespace App\Policies;

use App\Models\ExerciseAttempt;
use App\Models\User;

class ExerciseAttemptPolicy
{
    public function view(User $user, ExerciseAttempt $attempt): bool
    {
        return $attempt->learnerProfile?->user_id === $user->id;
    }

    public function update(User $user, ExerciseAttempt $attempt): bool
    {
        return $this->view($user, $attempt);
    }
}
