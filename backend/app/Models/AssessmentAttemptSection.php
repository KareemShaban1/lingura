<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentAttemptSection extends Model
{
    public const PENDING = 'pending';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const SKIPPED = 'skipped';

    public const NOT_AVAILABLE = 'not_available';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['theta' => 'float', 'standard_error' => 'float', 'completed_at' => 'datetime'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AssessmentAttempt::class, 'assessment_attempt_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(AssessmentSection::class, 'assessment_section_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function estimatedLevel(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'estimated_level_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(AssessmentAnswer::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::IN_PROGRESS], true);
    }
}
