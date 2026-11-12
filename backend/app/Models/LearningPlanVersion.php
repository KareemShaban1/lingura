<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable once created: historical learning decisions stay inspectable.
 */
class LearningPlanVersion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'inputs_snapshot' => 'array',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(LearningPlan::class, 'learning_plan_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LearningPlanVersionAllocation::class)->orderByDesc('priority_score');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LearningPlanItem::class)->orderBy('scheduled_date')->orderBy('position');
    }
}
