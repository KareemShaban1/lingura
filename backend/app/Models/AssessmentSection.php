<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentSection extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['target_standard_error' => 'float'];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
