<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class XpEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'xp_ledger';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['earned_on' => 'date'];
    }
}
