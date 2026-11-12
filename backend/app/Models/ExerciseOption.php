<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExerciseOption extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['is_correct', 'correct_position'];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean'];
    }
}
