<?php

return [
    // Features exposed to the client navigation. Modules not listed are not rendered (no dead buttons).
    'features' => array_filter(explode(',', env('LF_FEATURES', 'onboarding,assessment,plan,progress,courses,practice,grammar,vocabulary,tutor,writing'))),

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

    'speech' => [
        'max_upload_kb' => 4096,
        'max_seconds' => 120,
        'retention_days' => (int) env('LF_RECORDING_RETENTION_DAYS', 90),
        'allowed_mimes' => ['audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'video/webm'],
    ],

    'tutor' => [
        'history_messages' => 16, // context window sent to the model
        'max_message_chars' => 1000,
    ],

    'writing' => [
        'human_review_sample_rate' => 0.05, // share of AI evaluations flagged for human calibration
    ],

    'productive_assessment' => [
        'standard_error' => 0.7, // rubric-based estimates are less precise than adaptive closed items
        'short_response_standard_error' => 0.9,
    ],

    'adaptive' => [
        'window_days' => 14,
        'min_answers' => 15,
        'raise_at' => 0.85,
        'lower_at' => 0.5,
        'readiness_accuracy' => 0.8,
        'readiness_min_answers' => 10,
        'pattern_window_days' => 30,
        'recurring_threshold' => 3,
        'regenerate_cooldown_hours' => 24,
    ],

    'data_export_ttl_hours' => 48,
    'account_purge_after_days' => 30,
];
