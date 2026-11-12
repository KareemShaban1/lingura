<?php

namespace App\Policies;

use App\Models\LearnerProfile;
use App\Models\User;

class LearnerProfilePolicy
{
    public function view(User $user, LearnerProfile $profile): bool
    {
        return $profile->user_id === $user->id;
    }

    public function update(User $user, LearnerProfile $profile): bool
    {
        return $profile->user_id === $user->id;
    }

    public function delete(User $user, LearnerProfile $profile): bool
    {
        return $profile->user_id === $user->id;
    }
}
