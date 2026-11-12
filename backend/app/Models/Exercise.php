<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Exercise extends Model
{
    use HasPublicId, HasTranslations, SoftDeletes;

    public const TYPES = ['mcq', 'cloze', 'ordering', 'reading', 'listening', 'dictation'];

    /** Types that cannot be done without audio. */
    public const AUDIO_TYPES = ['listening', 'dictation'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['difficulty' => 'float', 'reviewed_at' => 'datetime'];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExerciseQuestion::class)->orderBy('position');
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }

    public function grammarTopic(): BelongsTo
    {
        return $this->belongsTo(GrammarTopic::class);
    }

    public function objectives(): BelongsToMany
    {
        return $this->belongsToMany(LearningObjective::class, 'exercise_objectives');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('review_status', 'published');
    }

    /** Published and usable: audio-dependent exercises need their audio generated first. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->published()->where(fn ($q) => $q
            ->whereNotIn('type', self::AUDIO_TYPES)
            ->orWhere(fn ($q) => $q->where('type', 'listening')->whereNotNull('audio_path'))
            ->orWhere(fn ($q) => $q->where('type', 'dictation')->whereDoesntHave('questions', fn ($q) => $q->whereNull('audio_path'))));
    }

    public function isAvailable(): bool
    {
        return match ($this->type) {
            'listening' => $this->audio_path !== null,
            'dictation' => $this->questions->every(fn ($q) => $q->audio_path !== null),
            default => true,
        } && $this->review_status === 'published';
    }
}
