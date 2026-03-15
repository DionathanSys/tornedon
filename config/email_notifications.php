<?php

return [
    'enabled' => env('EMAIL_NOTIFICATIONS_ENABLED', true),

    // Aceita alias ("resend") ou FQCN de um provider que implemente EmailProviderInterface.
    'provider' => env('EMAIL_NOTIFICATIONS_PROVIDER', 'resend'),

    'resend' => [
        'endpoint' => env('RESEND_API_ENDPOINT', 'https://api.resend.com/emails'),
    ],

    'alerts' => [
        'enabled' => env('EMAIL_ALERTS_ENABLED', true),
        'window_minutes' => (int) env('EMAIL_ALERTS_WINDOW_MINUTES', 30),
        'min_dispatches' => (int) env('EMAIL_ALERTS_MIN_DISPATCHES', 10),
        'failure_rate_threshold' => (float) env('EMAIL_ALERTS_FAILURE_RATE_THRESHOLD', 0.10),
    ],
];
