<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SkillProfile extends Model
{
    public const STATUS_ASSESSED = 'assessed';

    public const STATUS_NOT_ASSESSED = 'not_assessed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['theta' => 'float', 'standard_error' => 'float', 'assessed_at' => 'datetime'];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }

    public function learnerProfile(): BelongsTo
    {
        return $this->belongsTo(LearnerProfile::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(SkillScore::class)->orderBy('recorded_at');
    }
}
