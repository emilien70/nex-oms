<?php

return [
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
];
