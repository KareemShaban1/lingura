<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExerciseQuestion extends Model
{
    protected $guarded = ['id'];

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ExerciseOption::class)->orderBy('position');
    }

    public function acceptedAnswers(): HasMany
    {
        return $this->hasMany(ExerciseAcceptedAnswer::class);
    }
}
