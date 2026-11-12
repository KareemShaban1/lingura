<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserVocabulary;

class UserVocabularyPolicy
{
    public function update(User $user, UserVocabulary $card): bool
    {
        return $card->learnerProfile?->user_id === $user->id;
    }

    public function delete(User $user, UserVocabulary $card): bool
    {
        return $this->update($user, $card);
    }
}
