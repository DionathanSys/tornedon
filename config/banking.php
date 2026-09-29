<?php

use App\Services\Financial\Banking\Providers\IntegraBancosProvider;

return [
    'bank_slip_entitlement' => 'bank_slip_issuance',
    'default_auto_issuance' => false,
    'cancel_on_invoice_cancellation' => false,
    'providers' => [
        'integrabancos' => [
            'class' => IntegraBancosProvider::class,
            'base_url' => env('INTEGRABANCOS_BASE_URL'),
        ],
    ],
];
