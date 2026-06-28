<?php

use App\Services\Cnpj\Providers\BrasilApiProvider;
use App\Services\Cnpj\Providers\OpenCnpjaProvider;
use App\Services\Cnpj\Providers\ReceitaWsProvider;

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
    'providers' => env('CNPJ_PROVIDERS', 'brasil_api,open_cnpja'),

    /*
    |--------------------------------------------------------------------------
    | Mapeamento de providers
    |--------------------------------------------------------------------------
    */
    'provider_classes' => [
        'brasil_api' => BrasilApiProvider::class,
        'open_cnpja' => OpenCnpjaProvider::class,
        'receitaws' => ReceitaWsProvider::class,
    ],

    'provider_labels' => [
        'brasil_api' => 'BrasilAPI',
        'open_cnpja' => 'OpenCnpja',
        'receitaws' => 'ReceitaWS',
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuracao especifica por provider
    |--------------------------------------------------------------------------
    */
    'provider_settings' => [
        'brasil_api' => [
            'base_url' => env('CNPJ_BRASIL_API_BASE_URL', 'https://brasilapi.com.br/api/cnpj/v1'),
            'timeout' => (int) env('CNPJ_BRASIL_API_TIMEOUT', 15),
            'headers' => [],
            'rate_limit' => [
                'max_attempts' => (int) env('CNPJ_BRASIL_API_RATE_LIMIT_MAX_ATTEMPTS', 1000),
                'decay_seconds' => (int) env('CNPJ_BRASIL_API_RATE_LIMIT_DECAY_SECONDS', 60),
            ],
        ],
        'open_cnpja' => [
            'base_url' => env('CNPJ_OPEN_CNPJA_BASE_URL', 'https://open.cnpja.com/office'),
            'timeout' => (int) env('CNPJ_OPEN_CNPJA_TIMEOUT', 15),
            'headers' => [],
            'rate_limit' => [
                'max_attempts' => (int) env('CNPJ_OPEN_CNPJA_RATE_LIMIT_MAX_ATTEMPTS', 1000),
                'decay_seconds' => (int) env('CNPJ_OPEN_CNPJA_RATE_LIMIT_DECAY_SECONDS', 60),
            ],
        ],
        'receitaws' => [
            'base_url' => env('CNPJ_RECEITAWS_BASE_URL', 'https://www.receitaws.com.br/v1/cnpj'),
            'timeout' => (int) env('CNPJ_RECEITAWS_TIMEOUT', 20),
            'headers' => [],
            'rate_limit' => [
                'max_attempts' => (int) env('CNPJ_RECEITAWS_RATE_LIMIT_MAX_ATTEMPTS', 3),
                'decay_seconds' => (int) env('CNPJ_RECEITAWS_RATE_LIMIT_DECAY_SECONDS', 60),
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
