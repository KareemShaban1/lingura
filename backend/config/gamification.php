<?php

/*
 * XP is awarded only for verified learning outcomes (scored answers, completed lessons, successful
 * recalls, finished conversations/evaluations) — never for time spent or app opens. Each outcome has a
 * dedupe key so replaying the same exercise the same day earns nothing more.
 */
return [
    'xp' => [
        'exercise_max' => 10,        // × score (0–1); same exercise once per day
        'lesson' => 20,              // once per lesson
        'review' => 1,               // per remembered card (rating ≥ 2), once per card per day
        'conversation' => 15,        // finished conversation with ≥ min_turns learner turns
        'conversation_min_turns' => 4,
        'writing_base' => 10,        // + 2 × rubric band
        'assessment' => 30,
    ],
    'streak' => [
        'freeze_every_days' => 7, // one freeze earned per 7 consecutive days
        'max_freezes' => 2,
    ],
    'leaderboard_size' => 50,
    // Weekly challenges: three are drawn per ISO week, deterministically, from this pool.
    'challenge_pool' => [
        ['slug' => 'xp_300', 'metric' => 'xp', 'target' => 300, 'xp_reward' => 50],
        ['slug' => 'xp_600', 'metric' => 'xp', 'target' => 600, 'xp_reward' => 100],
        ['slug' => 'exercises_15', 'metric' => 'exercises', 'target' => 15, 'xp_reward' => 40],
        ['slug' => 'reviews_100', 'metric' => 'reviews', 'target' => 100, 'xp_reward' => 40],
        ['slug' => 'minutes_90', 'metric' => 'minutes', 'target' => 90, 'xp_reward' => 50],
        ['slug' => 'conversations_3', 'metric' => 'conversations', 'target' => 3, 'xp_reward' => 40],
    ],
];
