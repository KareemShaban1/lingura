<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
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

    public function activeForm(): ?ExamForm
    {
        return $this->forms()->where('is_active', true)->orderByDesc('version')->first();
    }
}
