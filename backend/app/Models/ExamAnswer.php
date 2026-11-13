<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamAnswer extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean', 'answered_at' => 'datetime'];
    }
}
