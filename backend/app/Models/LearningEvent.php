<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only analytics event. */
class LearningEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime', 'value' => 'float'];
    }
}
