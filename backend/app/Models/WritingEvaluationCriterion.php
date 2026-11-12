<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WritingEvaluationCriterion extends Model
{
    public $timestamps = false;

    protected $table = 'writing_evaluation_criteria';

    protected $guarded = ['id'];
}
