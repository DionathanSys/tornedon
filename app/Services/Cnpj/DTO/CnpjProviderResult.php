<?php

namespace App\Services\Cnpj\DTO;

class CnpjProviderResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?array $data = null,
        public readonly ?string $message = null,
        public readonly array $errors = [],
        public readonly int $status = 200,
        public readonly bool $shouldRetryNextProvider = true,
        public readonly array $meta = [],
    ) {}

    public static function success(array $data, array $meta = []): self
    {
        return new self(
            success: true,
            data: $data,
            status: 200,
            meta: $meta,
        );
    }

    public static function failure(
        string $message,
        array $errors = [],
        int $status = 422,
        bool $shouldRetryNextProvider = true,
    ): self {
        return new self(
            success: false,
            data: null,
            message: $message,
            errors: $errors,
            status: $status,
            shouldRetryNextProvider: $shouldRetryNextProvider,
            meta: [],
        );
    }

    public function isSuccess(): bool
    {
        return $this->success && is_array($this->data);
    }
}
