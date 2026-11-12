<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallengeProgress extends Model
{
    protected $table = 'challenge_progress';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
