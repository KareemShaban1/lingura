<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->profile;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'roles' => $this->roles->pluck('slug'),
            'is_staff' => $this->hasPermission('admin.access'),
            'profile' => [
                'native_language' => $profile?->nativeLanguage?->code,
                'interface_language' => $profile?->interfaceLanguage?->code ?? 'en',
                'interface_direction' => $profile?->interfaceLanguage?->direction ?? 'ltr',
                'timezone' => $profile?->timezone ?? 'UTC',
                'country_code' => $profile?->country_code,
            ],
            'preferences' => [
                'theme' => $this->preferences?->theme ?? 'system',
                'daily_reminder_enabled' => $this->preferences?->daily_reminder_enabled ?? true,
                'reminder_time' => $this->preferences?->reminder_time,
                'marketing_opt_in' => $this->preferences?->marketing_opt_in ?? false,
            ],
            'learner_profiles' => LearnerProfileResource::collection($this->learnerProfiles),
            'features' => array_values(config('learning.features')),
        ];
    }
}
