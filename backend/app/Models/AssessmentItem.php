<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssessmentItem extends Model
{
    use HasPublicId, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    public const FORMATS = ['mcq', 'cloze_mcq', 'reading_mcq', 'listening_mcq', 'true_false'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['difficulty' => 'float', 'reviewed_at' => 'datetime'];
    }

    public function options(): HasMany
    {
        return $this->hasMany(AssessmentItemOption::class)->orderBy('position');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('review_status', self::STATUS_PUBLISHED);
    }

    public function requiresAudio(): bool
    {
        return $this->format === 'listening_mcq';
    }
}
