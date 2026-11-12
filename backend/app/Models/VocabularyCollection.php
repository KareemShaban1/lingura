<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VocabularyCollection extends Model
{
    protected $guarded = ['id'];

    public function learnerProfile(): BelongsTo
    {
        return $this->belongsTo(LearnerProfile::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(UserVocabulary::class, 'vocabulary_collection_items')->withPivot('created_at');
    }
}
