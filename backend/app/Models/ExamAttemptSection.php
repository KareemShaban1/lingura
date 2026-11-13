<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamAttemptSection extends Model
{
    public $timestamps = false;

    public const OPEN = ['pending', 'in_progress'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime', 'deadline_at' => 'datetime', 'completed_at' => 'datetime',
            'theta' => 'float', 'standard_error' => 'float', 'estimate' => 'array',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ExamSection::class, 'exam_section_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExamAnswer::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
