<?php

namespace App\Policies;

use App\Models\LearningPlanItem;
use App\Models\User;

class LearningPlanItemPolicy
{
    public function update(User $user, LearningPlanItem $item): bool
    {
        return $item->version?->plan?->learnerProfile?->user_id === $user->id;
    }
}
