<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LanguagePair extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'source_language_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'target_language_id');
    }
}
