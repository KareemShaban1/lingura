<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningPreference extends Model
{
    public const ALLOWED_KEYS = ['prefers_audio', 'prefers_visual', 'explanation_language', 'session_style'];

    protected $guarded = ['id'];
}
