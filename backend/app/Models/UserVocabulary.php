<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserVocabulary extends Model
{
    protected $table = 'user_vocabulary';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['stability' => 'float', 'difficulty' => 'float', 'due_at' => 'datetime', 'last_reviewed_at' => 'datetime'];
    }

    public function word(): BelongsTo
    {
        return $this->belongsTo(VocabularyWord::class, 'vocabulary_word_id');
    }

    public function learnerProfile(): BelongsTo
    {
        return $this->belongsTo(LearnerProfile::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(FlashcardReview::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(VocabularyCollection::class, 'vocabulary_collection_items');
    }
}
