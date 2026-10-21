<?php

use App\Services\Financial\Pix\Providers\IntegraBancosPixProvider;

return [
    'entitlement' => 'pix_charge_issuance',
    'default_auto_issuance' => false,
    'public_link_ttl_minutes' => (int) env('PIX_PUBLIC_LINK_TTL_MINUTES', 60),
    'operation_query_interval_seconds' => (int) env('PIX_OPERATION_QUERY_INTERVAL_SECONDS', 30),
    'providers' => [
        'integrabancos' => [
            'class' => IntegraBancosPixProvider::class,
        ],
    ],
];
