<?php

return [
    'notifications' => [
        // Also email every in-app notification (uses the MAIL_* settings).
        'mail' => (bool) env('REMS_NOTIFICATION_MAIL', false),
    ],

    // Email one-time passcode as the second step of every sign-in and to verify new accounts.
    'otp' => [
        'enabled' => (bool) env('REMS_OTP_ENABLED', true),
        'length' => (int) env('REMS_OTP_LENGTH', 6),
        'ttl_minutes' => (int) env('REMS_OTP_TTL', 10),
        'max_attempts' => (int) env('REMS_OTP_MAX_ATTEMPTS', 5),
        'max_sends' => (int) env('REMS_OTP_MAX_SENDS', 5),
        'resend_seconds' => (int) env('REMS_OTP_RESEND_SECONDS', 60),
        // Also write codes to the log (local/testing only; never honoured in production).
        'log_codes' => (bool) env('REMS_OTP_LOG_CODES', false),
    ],

    'payments' => [
        // simulated | mpesa
        'driver' => env('PAYMENT_DRIVER', 'simulated'),
        'mpesa' => [
            'base_url' => env('MPESA_BASE_URL', 'https://sandbox.safaricom.co.ke'),
            'consumer_key' => env('MPESA_CONSUMER_KEY'),
            'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
            'shortcode' => env('MPESA_SHORTCODE'),
            'passkey' => env('MPESA_PASSKEY'),
            'callback_url' => env('MPESA_CALLBACK_URL', env('APP_URL').'/api/payments/mpesa/callback'),
        ],
    ],

    'uploads' => [
        'max_image_kb' => 8192,
        'max_video_kb' => 102400,
        'max_document_kb' => 20480,
    ],
];
