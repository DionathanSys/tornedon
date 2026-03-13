<?php

return [
    'enabled' => env('EMAIL_NOTIFICATIONS_ENABLED', true),

    // Aceita alias ("resend") ou FQCN de um provider que implemente EmailProviderInterface.
    'provider' => env('EMAIL_NOTIFICATIONS_PROVIDER', 'resend'),

    'resend' => [
        'endpoint' => env('RESEND_API_ENDPOINT', 'https://api.resend.com/emails'),
    ],
];
