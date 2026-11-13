<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamFormTask extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProductionTask::class, 'production_task_id');
    }
}
