<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyScheduleSlot extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer'];
    }
}
