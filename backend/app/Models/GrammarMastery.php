<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrammarMastery extends Model
{
    protected $table = 'grammar_mastery';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mastery' => 'float', 'last_practiced_at' => 'datetime'];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(GrammarTopic::class, 'grammar_topic_id');
    }
}
