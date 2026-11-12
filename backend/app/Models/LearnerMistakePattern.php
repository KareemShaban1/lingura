<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearnerMistakePattern extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
