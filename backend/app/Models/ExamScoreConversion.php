<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamScoreConversion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['theta' => 'float', 'value' => 'float'];
    }
}
