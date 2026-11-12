<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PronunciationResult extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['accuracy' => 'float', 'completeness' => 'float'];
    }

    public function audioRecording(): BelongsTo
    {
        return $this->belongsTo(AudioRecording::class);
    }

    public function words(): HasMany
    {
        return $this->hasMany(PronunciationWordResult::class)->orderBy('position');
    }
}
