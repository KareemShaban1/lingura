<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamItemOption extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ExamItem::class, 'exam_item_id');
    }
}
