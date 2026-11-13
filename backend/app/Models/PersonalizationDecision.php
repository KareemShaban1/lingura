<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonalizationDecision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['arms' => 'array', 'propensity' => 'float', 'reward' => 'float', 'rewarded_at' => 'datetime'];
    }
}
