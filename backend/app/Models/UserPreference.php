<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'daily_reminder_enabled' => 'boolean',
            'marketing_opt_in' => 'boolean',
            'data_processing_consent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
