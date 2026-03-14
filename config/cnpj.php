<?php

use App\Services\Cnpj\Providers\OpenCnpjaProvider;

return [
    /*
    |--------------------------------------------------------------------------
    | Provedores de consulta de CNPJ
    |--------------------------------------------------------------------------
    |
    | Aceita array ou string separada por virgula. A ordem define prioridade
    | de tentativa (fallback).
    |
    */
    'providers' => env('CNPJ_PROVIDERS', 'open_cnpja'),

    /*
    |--------------------------------------------------------------------------
    | Mapeamento de providers
    |--------------------------------------------------------------------------
    */
    'provider_classes' => [
        'open_cnpja' => OpenCnpjaProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuracao especifica por provider
    |--------------------------------------------------------------------------
    */
    'provider_settings' => [
        'open_cnpja' => [
            'base_url' => env('CNPJ_OPEN_CNPJA_BASE_URL', 'https://open.cnpja.com/office'),
            'timeout' => (int) env('CNPJ_OPEN_CNPJA_TIMEOUT', 15),
            'headers' => [],
            'rate_limit' => [
                'max_attempts' => (int) env('CNPJ_OPEN_CNPJA_RATE_LIMIT_MAX_ATTEMPTS', 5),
                'decay_seconds' => (int) env('CNPJ_OPEN_CNPJA_RATE_LIMIT_DECAY_SECONDS', 60),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache e rate limit local
    |--------------------------------------------------------------------------
    */
    'cache_ttl' => (int) env('CNPJ_CACHE_TTL', 604800),

    'rate_limit' => [
        'max_attempts' => (int) env('CNPJ_RATE_LIMIT_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('CNPJ_RATE_LIMIT_DECAY_SECONDS', 60),
    ],
];
