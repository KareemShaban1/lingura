<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentAnswer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'theta_after' => 'float',
            'se_after' => 'float',
            'answered_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssessmentItem::class, 'assessment_item_id');
    }
}
