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
    'company' => [
        'address' => env('COMPANY_ADDRESS', '123 Rue Example, 75000 Paris'),
        'phone' => env('COMPANY_PHONE', '+33 07 66 33 91 40'),
        'email' => env('COMPANY_EMAIL', 'faet@byfaet.fr'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'google' => [
        'client_id' => config('app.google_client_id'),
        'client_secret' => config('app.google_client_secret'),
        'redirect' => config('app.google_redirect'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'connect_client_id' => env('STRIPE_CONNECT_CLIENT_ID'),
        'express_webhook_secret' => env('STRIPE_EXPRESS_WEBHOOK_SECRET'),
    ],

    'paypal' => [
        'mode' => env('PAYPAL_MODE', 'sandbox'), // sandbox ou live
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        'merchant_email' => env('PAYPAL_MERCHANT_EMAIL'),
    ],

    'payment' => [
        'default_currency' => 'eur',
        'platform_fee_rate' => env('PLATFORM_FEE_RATE', 0.07), // 5%
        'max_amount' => env('PAYMENT_MAX_AMOUNT', 50000), // 50k€
        'session_timeout' => env('PAYMENT_SESSION_TIMEOUT', 3600), // 1h
        'max_retry_attempts' => env('PAYMENT_MAX_RETRIES', 3),
    ],
];
