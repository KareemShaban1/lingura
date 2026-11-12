<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StreakDay extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
