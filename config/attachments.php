<?php

use App\Enums\AttachmentType;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Disk
    |--------------------------------------------------------------------------
    |
    | A filesystem disk onde os anexos serão armazenados por padrão,
    | caso o tipo não defina um diferente.
    |
    */
    'default_disk' => env('ATTACHMENTS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Attachment Types Configuration
    |--------------------------------------------------------------------------
    |
    | Define as regras para cada tipo de anexo.
    | - mode: 'single_latest' (apenas a última versão ativa) ou 'multiple'
    | - allowed_mimes: array de mime types permitidos
    | - max_size: tamanho máximo em kilobytes
    | - disk: disco específico para este tipo (opcional)
    | - directory: diretório base específico (opcional)
    |
    */
    'types' => [
        AttachmentType::FISCAL_DOCUMENT->value => [
            'mode' => 'multiple',
            'allowed_mimes' => ['application/xml', 'text/xml', 'application/pdf'],
            'max_size' => 10240, // 10MB
        ],

        AttachmentType::SERVICE_PHOTO->value => [
            'mode' => 'multiple',
            'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_size' => 5120, // 5MB
        ],

        AttachmentType::CONTRACT->value => [
            'mode' => 'single_latest',
            'allowed_mimes' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'max_size' => 20480, // 20MB
        ],

        AttachmentType::GENERIC->value => [
            'mode' => 'multiple',
            'allowed_mimes' => [], // All
            'max_size' => 20480, // 20MB
        ],
    ],
];
