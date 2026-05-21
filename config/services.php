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

    'cloudflare' => [

        'token' => env('CLOUDFLARE_API_TOKEN'),

        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),

        'base_url' => 'https://api.cloudflare.com/client/v4',

    ],

    'stormwall' => [

        'api_key' => env('STORMWALL_API_KEY'),

        'service_id' => env('STORMWALL_SERVICE_ID'),

        'base_url' => env('STORMWALL_BASE_URL', 'https://api.stormwall.pro'),

        'domain_port' => (int) env('STORMWALL_DOMAIN_PORT', 80),

        'backend_port' => (int) env('STORMWALL_BACKEND_PORT', 80),

        'domain_uses_ssl' => filter_var(env('STORMWALL_DOMAIN_USES_SSL', false), FILTER_VALIDATE_BOOL),

        'backend_type' => env('STORMWALL_BACKEND_TYPE', 'balance'),

        'backend_weight' => (int) env('STORMWALL_BACKEND_WEIGHT', 1),

        'use_proxy_sni' => filter_var(env('STORMWALL_USE_PROXY_SNI', false), FILTER_VALIDATE_BOOL),

        'ssl' => [
            'lets_encrypt_enabled' => filter_var(env('STORMWALL_SSL_LE_ENABLED', false), FILTER_VALIDATE_BOOL),
            'www_included' => filter_var(env('STORMWALL_SSL_LE_WWW_INCLUDED', true), FILTER_VALIDATE_BOOL),
            'poll_delay_seconds' => (int) env('STORMWALL_SSL_POLL_DELAY_SECONDS', 300),
            'max_wait_minutes' => (int) env('STORMWALL_SSL_MAX_WAIT_MINUTES', 30),
        ],

        'retry' => [
            'times' => (int) env('STORMWALL_RETRY_TIMES', 3),
            'sleep' => (int) env('STORMWALL_RETRY_SLEEP', 500),
        ],

    ],
];
