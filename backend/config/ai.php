<?php

/*
| AI integration layer configuration.
| Secrets are read from the environment only on the backend and are never serialised to clients.
| Provider/model metadata (costs, enablement) lives in ai_providers / ai_models for admin control.
*/

return [
    'default_provider' => env('AI_DEFAULT_PROVIDER', 'fake'),

    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 60),
            'models' => [
                'text' => env('AI_TEXT_MODEL') ?: 'gpt-4.1-mini',
                'tts' => env('AI_TTS_MODEL') ?: 'gpt-4o-mini-tts',
                'stt' => env('AI_STT_MODEL') ?: 'gpt-4o-transcribe',
                'realtime' => env('AI_REALTIME_MODEL') ?: 'gpt-realtime',
            ],
            'tts_voice' => env('AI_TTS_VOICE', 'alloy'),
        ],
        'fake' => [
            'driver' => 'fake',
            'models' => ['text' => 'fake-text', 'tts' => 'fake-tts', 'stt' => 'fake-stt', 'realtime' => 'fake-realtime'],
        ],
    ],

    'retry' => ['times' => 2, 'sleep_ms' => 400],
];
