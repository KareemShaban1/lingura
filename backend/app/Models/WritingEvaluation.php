<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WritingEvaluation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['overall_score' => 'float', 'needs_human_review' => 'boolean', 'lexical_variety' => 'float', 'avg_sentence_length' => 'float'];
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(WritingEvaluationCriterion::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(WritingCorrection::class)->orderBy('start');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }
}
