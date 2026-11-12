<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class LearningObjective extends Model
{
    use HasTranslations;

    protected $guarded = ['id'];
}
