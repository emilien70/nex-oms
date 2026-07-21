<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'gus' => [
        'key' => env('GUS_API_KEY', env('REGON_API_KEY')),
        'url' => env('GUS_API_URL', 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc'),
        'timeout' => env('GUS_TIMEOUT', 10),
    ],

    'inpost' => [
        'token' => env('INPOST_API_TOKEN'),
        'organization_id' => env('INPOST_ORGANIZATION_ID'),
        'production_url' => env('INPOST_API_URL', 'https://api-shipx-pl.easypack24.net'),
        'sandbox_url' => env('INPOST_SANDBOX_API_URL', 'https://sandbox-api-shipx-pl.easypack24.net'),
        'timeout' => (int) env('INPOST_API_TIMEOUT', 20),
        'rate_limit_per_minute' => (int) env('INPOST_API_RATE_LIMIT_PER_MINUTE', 60),
        'sync_interval_minutes' => (int) env('INPOST_SYNC_INTERVAL_MINUTES', 60),
        'api_log_retention' => [
            'successful_status_days' => (int) env('INPOST_API_LOG_STATUS_DAYS', 45),
            'successful_days' => (int) env('INPOST_API_LOG_SUCCESS_DAYS', 180),
            'failed_days' => (int) env('INPOST_API_LOG_FAILED_DAYS', 365),
        ],
    ],

    'dpd' => [
        'login' => env('DPD_API_LOGIN'),
        'password' => env('DPD_API_PASSWORD'),
        'master_fid' => env('DPD_MASTER_FID'),
        'info_channel' => env('DPD_INFO_CHANNEL'),
        'production_url' => env('DPD_PRODUCTION_URL', 'https://dpdservices.dpd.com.pl'),
        'sandbox_url' => env('DPD_SANDBOX_URL', 'https://dpdservicesdemo.dpd.com.pl'),
        'info_services_url' => env('DPD_INFO_SERVICES_URL', 'https://dpdinfoservices.dpd.com.pl/DPDInfoServicesObjEventsService/DPDInfoServicesObjEvents'),
        'timeout' => env('DPD_TIMEOUT', 25),
        'rate_limit_per_minute' => env('DPD_RATE_LIMIT_PER_MINUTE', 60),
        'sync_interval_minutes' => env('DPD_SYNC_INTERVAL_MINUTES', 60),
    ],

    'allegro_shipping' => [
        'client_id' => env('ALLEGRO_CLIENT_ID'),
        'client_secret' => env('ALLEGRO_CLIENT_SECRET'),
        'access_token' => env('ALLEGRO_ACCESS_TOKEN'),
        'refresh_token' => env('ALLEGRO_REFRESH_TOKEN'),
        'production_url' => env('ALLEGRO_API_URL', 'https://api.allegro.pl'),
        'sandbox_url' => env('ALLEGRO_SANDBOX_API_URL', 'https://api.allegro.pl.allegrosandbox.pl'),
        'production_auth_url' => env('ALLEGRO_AUTH_URL', 'https://allegro.pl/auth/oauth/token'),
        'sandbox_auth_url' => env('ALLEGRO_SANDBOX_AUTH_URL', 'https://allegro.pl.allegrosandbox.pl/auth/oauth/token'),
        'production_device_url' => env('ALLEGRO_DEVICE_URL', 'https://allegro.pl/auth/oauth/device'),
        'sandbox_device_url' => env('ALLEGRO_SANDBOX_DEVICE_URL', 'https://allegro.pl.allegrosandbox.pl/auth/oauth/device'),
        'scopes' => 'allegro:api:shipments:read allegro:api:shipments:write',
        'timeout' => (int) env('ALLEGRO_API_TIMEOUT', 25),
        'rate_limit_per_minute' => (int) env('ALLEGRO_API_RATE_LIMIT_PER_MINUTE', 60),
        'sync_interval_minutes' => (int) env('ALLEGRO_SHIPMENT_SYNC_INTERVAL_MINUTES', 60),
    ],

    'automation_url' => [
        'connect_timeout' => (int) env('AUTOMATION_URL_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('AUTOMATION_URL_TIMEOUT', 10),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
