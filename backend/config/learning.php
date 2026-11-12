<?php

return [
    // Features exposed to the client navigation. Modules not listed are not rendered (no dead buttons).
    'features' => array_filter(explode(',', env('LF_FEATURES', 'onboarding,assessment,plan,progress'))),

    'assessment' => [
        'engine_version' => 'irt_v1',
        'prior_sd' => 1.5,
        'default_prior_mean' => -1.0, // A2 anchor when no self-reported level
        'grid_min' => -4.0,
        'grid_max' => 4.0,
        'grid_step' => 0.05,
        'top_k_selection' => 3,
        'confidence' => ['high' => 0.5, 'medium' => 0.8],
    ],

    'plan' => [
        'generator' => 'rules_v1',
        'horizon_days' => 7,
        'block_minutes' => [10, 15, 20],
        'min_daily_minutes' => 10,
        'max_daily_minutes' => 240,
        'focus_multiplier' => 1.3,
        'weakness_step' => 0.6,
        'not_assessed_multiplier' => 1.1,
    ],

    'media' => [
        'disk' => env('MEDIA_DISK', 'local'),
        'signed_url_ttl_minutes' => 5,
    ],

    'data_export_ttl_hours' => 48,
    'account_purge_after_days' => 30,
];
