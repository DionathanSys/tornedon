<?php

use App\Services\Financial\Pix\Providers\IntegraBancosPixProvider;

return [
    'entitlement' => 'pix_charge_issuance',
    'default_auto_issuance' => false,
    'providers' => [
        'integrabancos' => [
            'class' => IntegraBancosPixProvider::class,
        ],
    ],
];
