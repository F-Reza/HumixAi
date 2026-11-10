<?php

return [
    'max_characters' => (int) env('STUDIO_MAX_CHARACTERS', 60000),
    'max_file_kb' => (int) env('STUDIO_MAX_FILE_KB', 6144),
    'import_max_bytes' => (int) env('STUDIO_MAX_FILE_KB', 6144) * 1024,
    'daily_word_limit' => (int) env('STUDIO_DAILY_WORD_LIMIT', 20000),
    'usage_timezone' => env('APP_TIMEZONE', 'Asia/Dhaka'),
    'max_batch_size' => (int) env('STUDIO_MAX_BATCH_SIZE', 10),
    'queue_connection' => env('STUDIO_QUEUE_CONNECTION', 'database'),
    'job_retention_hours' => (int) env('STUDIO_JOB_RETENTION_HOURS', 24),
    'pdftotext_path' => env('PDFTOTEXT_PATH', 'pdftotext'),
    'url_import_enabled' => filter_var(env('STUDIO_URL_IMPORT_ENABLED', true), FILTER_VALIDATE_BOOL),
    'url_import_max_bytes' => (int) env('STUDIO_URL_IMPORT_MAX_BYTES', 1048576),
    'allow_registration' => filter_var(env('STUDIO_ALLOW_REGISTRATION', true), FILTER_VALIDATE_BOOL),
];
