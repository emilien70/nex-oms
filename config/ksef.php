<?php

return [
    'latarnia' => [
        'sync_enabled' => env('KSEF_LATARNIA_SYNC_ENABLED', false),
        'freshness_minutes' => 15,
    ],

    'invoice_submission_enabled' => env('KSEF_INVOICE_SUBMISSION_ENABLED', false),

    'base_urls' => [
        'test' => env('KSEF_TEST_BASE_URL', 'https://api-test.ksef.mf.gov.pl/v2'),
        'demo' => env('KSEF_DEMO_BASE_URL', 'https://api-demo.ksef.mf.gov.pl/v2'),
        'production' => env('KSEF_PRODUCTION_BASE_URL', 'https://api.ksef.mf.gov.pl/v2'),
    ],

    'qr_base_urls' => [
        'test' => 'https://qr-test.ksef.mf.gov.pl',
        'demo' => 'https://qr-demo.ksef.mf.gov.pl',
        'production' => 'https://qr.ksef.mf.gov.pl',
    ],

    'connect_timeout_seconds' => 5,
    'request_timeout_seconds' => 15,
    'auth_poll_interval_ms' => 500,
    'auth_poll_max_attempts' => 20,
    'access_token_refresh_skew_seconds' => 60,

    'automatic_submission' => [
        'connection' => 'ksef_submit',
        'queue' => 'ksef-submit',
        'timeout_seconds' => 120,
        'unique_for_seconds' => 21600,
    ],

    'follow_up' => [
        'queue' => 'ksef',
        'dispatch_batch_size' => 20,
        'unique_for_seconds' => 21600,
        'lock_seconds' => 120,
        'backoff_seconds' => [60, 300, 900, 3600],
        'rate_limits' => [
            'status' => [
                'per_second' => 20,
                'per_minute' => 90,
                'per_hour' => 900,
            ],
            'reconcile' => [
                'per_second' => 5,
                'per_minute' => 15,
                'per_hour' => 90,
            ],
            'upo' => [
                'per_second' => 5,
                'per_minute' => 20,
                'per_hour' => 90,
            ],
        ],
    ],
];
