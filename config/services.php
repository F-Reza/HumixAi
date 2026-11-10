<?php

return [
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 60),
        'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 8192),
    ],
    'gptzero' => [
        'api_key' => env('GPTZERO_API_KEY'),
        'base_url' => env('GPTZERO_BASE_URL', 'https://api.gptzero.me'),
        'model_version' => env('GPTZERO_MODEL_VERSION'),
        'min_words' => (int) env('GPTZERO_MIN_WORDS', 80),
        'max_characters' => (int) env('GPTZERO_MAX_CHARACTERS', 60000),
        'timeout' => (int) env('GPTZERO_TIMEOUT', 45),
        'languages' => ['en', 'es', 'fr', 'de', 'pt'],
    ],
    'walter' => [
        'api_key' => env('WALTER_API_KEY'),
        'base_url' => env('WALTER_BASE_URL', 'https://developer-portal.walterwrites.ai/api'),
        'timeout' => (int) env('WALTER_TIMEOUT', 60),
    ],
];
