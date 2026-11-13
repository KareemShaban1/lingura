<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamFramework extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ExamSection::class)->orderBy('position');
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(ExamScoreConversion::class)->orderBy('theta');
    }

    public function forms(): HasMany
    {
        return $this->hasMany(ExamForm::class);
    }

    public function targetLevel(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'target_cefr_level_id');
    }

    public function priorLevel(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'prior_cefr_level_id');
    }

    /** @return Collection<int, ExamForm> active forms in a stable order (by key) */
    public function activeForms()
    {
        return $this->forms()->where('is_active', true)->orderBy('key')->get();
    }

    public function activeForm(): ?ExamForm
    {
        return $this->activeForms()->first();
    }

    /** The active form this learner has taken least often (ties: first in order), so retakes meet new material. */
    public function formFor(LearnerProfile $profile): ?ExamForm
    {
        $forms = $this->activeForms();
        $taken = ExamAttempt::where('learner_profile_id', $profile->id)->whereIn('exam_form_id', $forms->modelKeys())
            ->selectRaw('exam_form_id, count(*) as n')->groupBy('exam_form_id')->pluck('n', 'exam_form_id');

        return $forms->sortBy(fn (ExamForm $f, int $i) => [(int) ($taken[$f->id] ?? 0), $i])->first();
    }
}
