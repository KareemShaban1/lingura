<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Streak extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_active_on' => 'date'];
    }
}
