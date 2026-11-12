<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
    use HasPublicId, HasTranslations, SoftDeletes;

    protected $guarded = ['id'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(CourseUnit::class, 'course_unit_id');
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
        return $this->belongsToMany(LearningObjective::class, 'lesson_objectives');
    }

    public function words(): BelongsToMany
    {
        return $this->belongsToMany(VocabularyWord::class, 'lesson_words')->withPivot('position')->orderByPivot('position');
    }

    public function exercises(): BelongsToMany
    {
        return $this->belongsToMany(Exercise::class, 'lesson_exercises')->withPivot('position')->orderByPivot('position');
    }
}
