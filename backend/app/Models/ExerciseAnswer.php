<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExerciseAnswer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean', 'score' => 'float', 'answered_at' => 'datetime'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExerciseQuestion::class, 'exercise_question_id');
    }
}
