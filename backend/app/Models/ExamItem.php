<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamItem extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['difficulty' => 'float'];
    }

    public function options(): HasMany
    {
        return $this->hasMany(ExamItemOption::class)->orderBy('position');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ExamSection::class, 'exam_section_id');
    }
}
