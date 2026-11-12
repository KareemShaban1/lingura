<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningPlanItem extends Model
{
    use HasPublicId;

    public const PENDING = 'pending';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const SKIPPED = 'skipped';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scheduled_date' => 'date', 'completed_at' => 'datetime'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(LearningPlanVersion::class, 'learning_plan_version_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function targetLevel(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'target_level_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(LearningSession::class);
    }
}
