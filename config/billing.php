<?php

return [
    'enabled' => filter_var(env('BILLING_ENABLED', false), FILTER_VALIDATE_BOOL),
    'secret_key' => env('STRIPE_SECRET_KEY'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    // Omit the header by default and use the Stripe account's configured API version.
    'api_version' => env('STRIPE_API_VERSION'),
    'plans' => [
        'pro' => ['name' => 'Pro', 'price_id' => env('STRIPE_PRO_PRICE_ID'), 'daily_word_limit' => (int) env('PRO_DAILY_WORD_LIMIT', 100000)],
    ],
];
