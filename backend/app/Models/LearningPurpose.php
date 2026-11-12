<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LearningPurpose extends Model
{
    protected $guarded = ['id'];

    public function skillWeights(): HasMany
    {
        return $this->hasMany(LearningPurposeSkillWeight::class);
    }
}
