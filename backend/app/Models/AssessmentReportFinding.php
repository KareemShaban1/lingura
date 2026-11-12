<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentReportFinding extends Model
{
    protected $guarded = ['id'];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
