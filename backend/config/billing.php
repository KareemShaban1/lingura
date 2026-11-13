<?php

/*
 * Billing & entitlements. Plans and limits are seeded from here into `subscription_plans` /
 * `plan_entitlements` (BillingPlanSeeder) and can then be edited by admins; the database is the
 * source of truth at runtime. Prices shown to learners come from the database; the amount actually
 * charged is the provider price (`provider_price_id`), so keep both in sync.
 */
return [
    'driver' => env('BILLING_DRIVER', 'fake'), // stripe | fake (local/testing only)

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_base' => env('STRIPE_API_BASE', 'https://api.stripe.com'),
        'webhook_tolerance_seconds' => 300,
        'timeout' => 20,
    ],

    // Realtime voice is pre-charged in blocks; the client ends the call when the block runs out.
    'realtime_block_minutes' => (int) env('LF_REALTIME_BLOCK_MINUTES', 5),

    'invitation_ttl_days' => 7,

    /*
     * feature => period. day/month counters use the learner's timezone; `total` is a standing count.
     */
    'features' => [
        'tutor_messages' => 'day',
        'realtime_minutes' => 'month',
        'writing_evaluations' => 'month',
        'pronunciation_checks' => 'day',
        'placement_productive' => 'month',
        'target_languages' => 'total',
        'exam_mocks' => 'month',
    ],

    'plans' => [
        'free' => ['tier' => 'free', 'interval' => null, 'price_minor' => 0, 'rank' => 0, 'seats' => 1, 'limits' => [
            'tutor_messages' => 10, 'realtime_minutes' => 0, 'writing_evaluations' => 3,
            'pronunciation_checks' => 10, 'placement_productive' => 2, 'target_languages' => 1, 'exam_mocks' => 1,
        ]],
        'premium_monthly' => ['tier' => 'premium', 'interval' => 'month', 'price_minor' => 1299, 'rank' => 10, 'seats' => 1, 'price_env' => 'STRIPE_PRICE_PREMIUM_MONTHLY'],
        'premium_yearly' => ['tier' => 'premium', 'interval' => 'year', 'price_minor' => 9999, 'rank' => 10, 'seats' => 1, 'price_env' => 'STRIPE_PRICE_PREMIUM_YEARLY'],
        'pro_monthly' => ['tier' => 'pro', 'interval' => 'month', 'price_minor' => 2499, 'rank' => 20, 'seats' => 1, 'price_env' => 'STRIPE_PRICE_PRO_MONTHLY'],
        'pro_yearly' => ['tier' => 'pro', 'interval' => 'year', 'price_minor' => 19999, 'rank' => 20, 'seats' => 1, 'price_env' => 'STRIPE_PRICE_PRO_YEARLY'],
        'family_monthly' => ['tier' => 'family', 'interval' => 'month', 'price_minor' => 1999, 'rank' => 15, 'seats' => 6, 'price_env' => 'STRIPE_PRICE_FAMILY_MONTHLY'],
        'family_yearly' => ['tier' => 'family', 'interval' => 'year', 'price_minor' => 15999, 'rank' => 15, 'seats' => 6, 'price_env' => 'STRIPE_PRICE_FAMILY_YEARLY'],
    ],

    // Limits per tier (monthly and yearly variants share them). null = unlimited.
    'tier_limits' => [
        'premium' => ['tutor_messages' => 150, 'realtime_minutes' => 30, 'writing_evaluations' => 40, 'pronunciation_checks' => 100, 'placement_productive' => 10, 'target_languages' => 3, 'exam_mocks' => 4],
        'family' => ['tutor_messages' => 150, 'realtime_minutes' => 30, 'writing_evaluations' => 40, 'pronunciation_checks' => 100, 'placement_productive' => 10, 'target_languages' => 3, 'exam_mocks' => 4],
        'pro' => ['tutor_messages' => 400, 'realtime_minutes' => 120, 'writing_evaluations' => 100, 'pronunciation_checks' => 300, 'placement_productive' => 20, 'target_languages' => null, 'exam_mocks' => 10],
    ],
];
