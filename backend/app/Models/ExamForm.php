<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamForm extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function framework(): BelongsTo
    {
        return $this->belongsTo(ExamFramework::class, 'exam_framework_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExamItem::class)->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ExamFormTask::class);
    }
}
