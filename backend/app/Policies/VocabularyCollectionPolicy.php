<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VocabularyCollection;

class VocabularyCollectionPolicy
{
    public function update(User $user, VocabularyCollection $collection): bool
    {
        return $collection->learnerProfile?->user_id === $user->id;
    }

    public function delete(User $user, VocabularyCollection $collection): bool
    {
        return $this->update($user, $collection);
    }
}
