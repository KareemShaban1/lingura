<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WritingSubmission;

class WritingSubmissionPolicy
{
    public function view(User $user, WritingSubmission $submission): bool
    {
        return $submission->learnerProfile?->user_id === $user->id;
    }
}
