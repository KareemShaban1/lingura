<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiConversation extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function learnerProfile(): BelongsTo
    {
        return $this->belongsTo(LearnerProfile::class);
    }

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(AiTutor::class, 'ai_tutor_id');
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(ConversationScenario::class, 'conversation_scenario_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CefrLevel::class, 'cefr_level_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(AiFeedback::class)->orderBy('id');
    }

    public function report(): HasOne
    {
        return $this->hasOne(AiConversationReport::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
