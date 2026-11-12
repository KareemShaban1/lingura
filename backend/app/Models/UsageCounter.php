<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageCounter extends Model
{
    public const CREATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_start' => 'date'];
    }
}
