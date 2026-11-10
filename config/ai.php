<?php

return [
    // Off by default. Enable only after migration, service-account and EU checks.
    'enabled' => (bool) env('AI_VERTEX_ENABLED', false),

    'vertex' => [
        // Blank values fall back to the existing config('services.vertex_ai.*').
        'project_id' => env('AI_VERTEX_PROJECT_ID'),
        'credentials_path' => env('AI_VERTEX_CREDENTIALS_PATH'),
        'location' => env('AI_VERTEX_LOCATION', 'eu'),
        'timeout_seconds' => (int) env('AI_VERTEX_TIMEOUT', 90),
        'max_input_chars' => (int) env('AI_VERTEX_MAX_INPUT_CHARS', 40000),
        'max_attempts' => (int) env('AI_VERTEX_MAX_ATTEMPTS', 1),
    ],

    'workflows' => [
        'ocr_enhancement' => [
            'model' => env('AI_MODEL_OCR_ENHANCEMENT', 'gemini-3.5-flash-lite'),
            'temperature' => 0.0,
            'max_output_tokens' => 8192,
            'thinking_level' => 'MINIMAL',
        ],
        'dekurz_generation' => [
            'model' => env('AI_MODEL_DEKURZ_GENERATION', 'gemini-3.5-flash'),
            'temperature' => 0.1,
            'max_output_tokens' => 8192,
            'thinking_level' => 'LOW',
        ],
        'dekurz_enhancement' => [
            'model' => env('AI_MODEL_DEKURZ_ENHANCEMENT', 'gemini-3.5-flash-lite'),
            'temperature' => 0.0,
            'max_output_tokens' => 4096,
            'thinking_level' => 'MINIMAL',
        ],
        'proposal_extraction' => [
            'model' => env('AI_MODEL_PROPOSAL_EXTRACTION', 'gemini-3.5-flash-lite'),
            'temperature' => 0.0,
            'max_output_tokens' => 8192,
            'thinking_level' => 'MINIMAL',
        ],
    ],

    // Rates are deliberately NOT hardcoded: configure verified current rates
    // for your location, traffic tier and contracts before using estimated USD.
    // Null means "unknown cost", never "free". See README.
    'prices' => [
        'gemini-3.5-flash' => [
            'input_per_million_usd' => env('AI_PRICE_FLASH_INPUT_PER_1M_USD'),
            'output_per_million_usd' => env('AI_PRICE_FLASH_OUTPUT_PER_1M_USD'),
            'cached_input_per_million_usd' => env('AI_PRICE_FLASH_CACHED_INPUT_PER_1M_USD'),
        ],
        'gemini-3.5-flash-lite' => [
            'input_per_million_usd' => env('AI_PRICE_FLASH_LITE_INPUT_PER_1M_USD'),
            'output_per_million_usd' => env('AI_PRICE_FLASH_LITE_OUTPUT_PER_1M_USD'),
            'cached_input_per_million_usd' => env('AI_PRICE_FLASH_LITE_CACHED_INPUT_PER_1M_USD'),
        ],
    ],
];
