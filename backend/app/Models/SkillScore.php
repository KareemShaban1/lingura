<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillScore extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['theta' => 'float', 'standard_error' => 'float', 'recorded_at' => 'datetime'];
    }

    public function skillProfile(): BelongsTo
    {
        return $this->belongsTo(SkillProfile::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }
}
