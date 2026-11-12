<?php

namespace App\Policies;

use App\Models\LearningSession;
use App\Models\User;

class LearningSessionPolicy
{
    public function view(User $user, LearningSession $session): bool
    {
        return $session->learnerProfile?->user_id === $user->id;
    }

    public function update(User $user, LearningSession $session): bool
    {
        return $this->view($user, $session);
    }
}
