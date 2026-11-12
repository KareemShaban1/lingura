<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    public const LISTENING = 'listening';

    public const SPEAKING = 'speaking';

    public const READING = 'reading';

    public const WRITING = 'writing';

    public const GRAMMAR = 'grammar';

    public const VOCABULARY = 'vocabulary';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_productive' => 'boolean'];
    }
}
