<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasPublicId, HasTranslations, SoftDeletes;

    protected $guarded = ['id'];

    public function units(): HasMany
    {
        return $this->hasMany(CourseUnit::class)->orderBy('position');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function levelFrom(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'level_from_id');
    }

    public function levelTo(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'level_to_id');
    }
}
