<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateLearnerProfileRequest extends StoreLearnerProfileRequest
{
    public function rules(): array
    {
        return array_merge([
            'purpose' => ['sometimes', 'string', Rule::exists('learning_purposes', 'slug')],
            'daily_minutes' => ['sometimes', 'integer', 'min:'.config('learning.plan.min_daily_minutes'), 'max:'.config('learning.plan.max_daily_minutes')],
        ], self::sharedRules());
    }
}
