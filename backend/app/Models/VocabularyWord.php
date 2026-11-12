<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VocabularyWord extends Model
{
    use HasTranslations, SoftDeletes;

    protected $guarded = ['id'];

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }

    public function forms(): HasMany
    {
        return $this->hasMany(VocabularyForm::class);
    }

    public function examples(): HasMany
    {
        return $this->hasMany(VocabularyExample::class)->orderBy('position');
    }

    public function relations(): HasMany
    {
        return $this->hasMany(VocabularyRelation::class);
    }
}
