<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamSection extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function framework(): BelongsTo
    {
        return $this->belongsTo(ExamFramework::class, 'exam_framework_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
