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

    'google' => [
        'maps_key' => env('GOOGLE_MAPS_API_KEY'),
        'geocoding_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'route_service' => [
        'base_url' => env('ROUTE_SERVICE_BASE_URL', 'http://127.0.0.1:1515'),
        'endpoint' => env('ROUTE_SERVICE_DISTANCE_ENDPOINT', '/tsp-solver'),
        'timeout' => env('ROUTE_SERVICE_TIMEOUT', 200),
    ],

    'udzs' => [
        'base_url' => env('UDZS_BASE_URL', 'https://api.udzs-sk.sk/api'),
        'email' => env('UDZS_EMAIL'),
        'password' => env('UDZS_PASSWORD'),
        'token_ttl' => env('UDZS_TOKEN_TTL', 3300),
        'timeout' => env('UDZS_TIMEOUT', 10),
    ],

    'eoverenie' => [
        'base_url' => env('EOVERENIE_BASE_URL', 'https://eportalapi.udzs-sk.sk/api/external'),
        'email' => env('EOVERENIE_EMAIL'),
        'password' => env('EOVERENIE_PASSWORD'),
        'token_ttl' => env('EOVERENIE_TOKEN_TTL', 3300),
        'timeout' => env('EOVERENIE_TIMEOUT', 10),
    ],

    // StudioKristian is the central SaaS billing platform. ADOCare is only a
    // consumer of its Billing API - never store Stripe credentials here.
    'studiokristian_billing' => [
        'base_url' => env('STUDIOKRISTIAN_BILLING_URL'),
        'project_token' => env('STUDIOKRISTIAN_BILLING_PROJECT_TOKEN'),
        'timeout' => env('STUDIOKRISTIAN_BILLING_TIMEOUT', 10),
        // Separate, shorter timeout just for establishing the connection/DNS resolution, so an
        // unreachable host fails fast instead of hanging for the full request timeout.
        'connect_timeout' => env('STUDIOKRISTIAN_BILLING_CONNECT_TIMEOUT', 5),
        'plans_cache_ttl' => env('STUDIOKRISTIAN_BILLING_PLANS_CACHE_TTL', 300),
    ],

    // StudioKristian is authoritative for trial duration/credits (POST/GET
    // /api/v1/billing/customer/trial). This is only a fallback used to populate the local
    // display cache if StudioKristian's response omits an end date - never a Stripe trial.
    'adocare_trial' => [
        'days' => env('ADOCARE_TRIAL_DAYS', 14),
    ],

    'ocr' => [
        'url' => env('OCR_SERVICE_URL', 'http://127.0.0.1:8081'),
    ],

    'gemini' => [
        'api_key' => env('GOOGLE_GEMINI_API_KEY'),
        'model' => env('GOOGLE_GEMINI_MODEL', 'gemini-2.0-flash'),
    ],

    'vertex_ai' => [
        'project_id' => env('VERTEX_PROJECT_ID'),
        'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),

        'proposal' => [
            'location' => env(
                'VERTEX_PROPOSAL_LOCATION',
                'europe-southwest1'
            ),
            'endpoint_id' => env(
                'VERTEX_PROPOSAL_ENDPOINT_ID'
            ),
        ],

        'dekurz' => [
            'location' => env(
                'VERTEX_DEKURZ_LOCATION',
                'europe-southwest1'
            ),
            'endpoint_id' => env(
                'VERTEX_DEKURZ_ENDPOINT_ID'
            ),
        ],

        'general_location' => env(
            'VERTEX_GENERAL_LOCATION',
            'global'
        ),

        'general_model' => env(
            'VERTEX_GENERAL_MODEL',
            'gemini-2.5-flash-lite'
        ),

        'general_models' => env(
            'VERTEX_GENERAL_MODELS',
            ''
        ),

        'auto_train' => [
            'enabled' => env(
                'VERTEX_AUTOTRAIN_ENABLED',
                false
            ),
            'frequency' => env(
                'VERTEX_AUTOTRAIN_FREQUENCY',
                'monthly'
            ),
            'day' => (int) env(
                'VERTEX_AUTOTRAIN_DAY',
                1
            ),
            'weekday' => (int) env(
                'VERTEX_AUTOTRAIN_WEEKDAY',
                1
            ),
            'time' => env(
                'VERTEX_AUTOTRAIN_TIME',
                '02:30'
            ),
            'min_new_feedback' => (int) env(
                'VERTEX_AUTOTRAIN_MIN_NEW_FEEDBACK',
                25
            ),
            'source' => env(
                'VERTEX_AUTOTRAIN_SOURCE',
                'proposal_ai_prefill'
            ),
            'dataset_disk' => env(
                'VERTEX_AUTOTRAIN_DATASET_DISK',
                'gcs'
            ),
            'dataset_prefix' => env(
                'VERTEX_AUTOTRAIN_DATASET_PREFIX',
                'ai/dekurz-feedback'
            ),
            'local_dataset_path' => env(
                'VERTEX_AUTOTRAIN_LOCAL_DATASET_PATH',
                'storage/app/private/ai-dataset-dekurz-feedback/train.jsonl'
            ),
            'state_path' => env(
                'VERTEX_AUTOTRAIN_STATE_PATH',
                'ai/dekurz-autotrain/state.json'
            ),
            'training_endpoint' => env(
                'VERTEX_AUTOTRAIN_TRAINING_ENDPOINT',
                'tuningJobs'
            ),
            'base_model' => env(
                'VERTEX_AUTOTRAIN_BASE_MODEL',
                'publishers/google/models/gemini-2.5-flash'
            ),
            'base_models' => env(
                'VERTEX_AUTOTRAIN_BASE_MODELS',
                ''
            ),
            'min_json_validity' => (float) env(
                'VERTEX_AUTOTRAIN_MIN_JSON_VALIDITY',
                1.00
            ),
            'min_required_fields_rate' => (float) env(
                'VERTEX_AUTOTRAIN_MIN_REQUIRED_FIELDS_RATE',
                1.00
            ),
            'max_score_regression' => (float) env(
                'VERTEX_AUTOTRAIN_MAX_SCORE_REGRESSION',
                0.01
            ),
            'max_http_failures' => (int) env(
                'VERTEX_AUTOTRAIN_MAX_HTTP_FAILURES',
                0
            ),
            'max_critical_errors' => (int) env(
                'VERTEX_AUTOTRAIN_MAX_CRITICAL_ERRORS',
                0
            ),
            'poll_minutes' => (int) env(
                'VERTEX_AUTOTRAIN_POLL_MINUTES',
                15
            ),
            'max_hours' => (int) env(
                'VERTEX_AUTOTRAIN_MAX_HOURS',
                48
            ),
            'retention_days' => (int) env(
                'VERTEX_AUTOTRAIN_RETENTION_DAYS',
                180
            ),
            'keep_successful_versions' => (int) env(
                'VERTEX_AUTOTRAIN_KEEP_SUCCESSFUL_VERSIONS',
                4
            ),
            'require_manual_approval' => (bool) env(
                'VERTEX_AUTOTRAIN_REQUIRE_MANUAL_APPROVAL',
                false
            ),
            'rollback_on_health_failure' => (bool) env(
                'VERTEX_AUTOTRAIN_ROLLBACK_ON_HEALTH_FAILURE',
                true
            ),
            'runtime_fallback' => (bool) env(
                'VERTEX_AUTOTRAIN_RUNTIME_FALLBACK',
                true
            ),
            'notification_emails' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('VERTEX_AUTOTRAIN_NOTIFICATION_EMAILS', ''))
            ))),
        ],
    ],
];
