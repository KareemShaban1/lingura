<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LanguageCapability extends Model
{
    public const SUPPORTED = 'supported';

    public const BETA = 'beta';

    public const UNSUPPORTED = 'unsupported';

    public const FEATURES = ['content_generation', 'tts', 'stt', 'realtime_voice', 'pronunciation', 'writing_evaluation'];

    protected $guarded = ['id'];

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function isUsable(): bool
    {
        return in_array($this->status, [self::SUPPORTED, self::BETA], true);
    }
}
