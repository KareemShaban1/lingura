<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationScenario extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function minLevel(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'min_level_id');
    }

    public function maxLevel(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'max_level_id');
    }
}
