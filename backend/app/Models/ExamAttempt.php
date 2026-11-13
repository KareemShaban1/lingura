<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamAttempt extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime', 'result' => 'array'];
    }

    public function learnerProfile(): BelongsTo
    {
        return $this->belongsTo(LearnerProfile::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(ExamForm::class, 'exam_form_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ExamAttemptSection::class);
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }
}
