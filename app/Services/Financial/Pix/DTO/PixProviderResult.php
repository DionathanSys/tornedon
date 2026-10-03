<?php

namespace App\Services\Financial\Pix\DTO;

final readonly class PixProviderResult
{
    public function __construct(
        public bool $successful,
        public bool $retryable,
        public int $httpStatus,
        public ?string $providerIdentification = null,
        public ?string $providerChargeId = null,
        public ?string $qrCode = null,
        public ?string $pixCopyPaste = null,
        public ?string $statusCode = null,
        public ?string $statusMessage = null,
        public ?float $amount = null,
        public bool $paid = false,
        public array $payload = [],
        public array $errors = [],
        public ?string $message = null,
    ) {}
}
