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

    'ors' => [
        'key' => env('ORS_API_KEY'),
    ],

    'eoverenie' => [
        'base_url' => env('EOVERENIE_BASE_URL'),
        'email' => env('EOVERENIE_EMAIL'),
        'password' => env('EOVERENIE_PASSWORD'),
        'timeout' => (int) env('EOVERENIE_TIMEOUT', 10),
        'token_ttl' => (int) env('EOVERENIE_TOKEN_TTL', 3300),
    ],

    'route_service' => [
        'base_url' => env('ROUTE_SERVICE_BASE_URL'),
        'endpoint' => env('ROUTE_SERVICE_ENDPOINT', '/tsp-solver'),
        'timeout' => (int) env('ROUTE_SERVICE_TIMEOUT', 3300),
    ],

];
