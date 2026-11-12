<?php

namespace App\Http\Requests;

use App\Models\LearningPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLearnerProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return array_merge([
            'target_language' => ['required', 'string', Rule::exists('languages', 'code')->where('is_active', true)->where('is_target_enabled', true)],
            'purpose' => ['required', 'string', Rule::exists('learning_purposes', 'slug')],
            'daily_minutes' => ['required', 'integer', 'min:'.config('learning.plan.min_daily_minutes'), 'max:'.config('learning.plan.max_daily_minutes')],
        ], self::sharedRules());
    }

    public static function sharedRules(): array
    {
        return [
            'variant' => ['nullable', 'string', 'max:16'],
            'self_reported_level' => ['nullable', 'string', Rule::exists('cefr_levels', 'code')],
            'target_level' => ['nullable', 'string', Rule::exists('cefr_levels', 'code')],
            'focus_skills' => ['nullable', 'array', 'max:6'],
            'focus_skills.*' => ['string', 'distinct', Rule::exists('skills', 'slug')],
            'schedule' => ['nullable', 'array', 'max:7'],
            'schedule.*.day' => ['required', 'integer', 'between:0,6', 'distinct'],
            'schedule.*.time' => ['nullable', 'string', 'regex:/^(morning|afternoon|evening|([01]\d|2[0-3]):[0-5]\d)$/'],
            'preferences' => ['nullable', 'array'],
            'preferences.*' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            foreach (array_keys($this->input('preferences', []) ?? []) as $key) {
                if (! in_array($key, LearningPreference::ALLOWED_KEYS, true)) {
                    $validator->errors()->add("preferences.{$key}", 'Unknown preference.');
                }
            }
        }];
    }
}
