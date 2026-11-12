<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CefrLevel extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ordinal' => 'integer', 'anchor_theta' => 'float'];
    }
}
