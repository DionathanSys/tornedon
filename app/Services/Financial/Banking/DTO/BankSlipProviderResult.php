<?php

namespace App\Services\Financial\Banking\DTO;

final readonly class BankSlipProviderResult
{
    public function __construct(
        public bool $successful,
        public bool $retryable,
        public int $httpStatus,
        public ?string $providerIdentification = null,
        public ?string $providerChargeId = null,
        public ?string $pdfUrl = null,
        public ?string $digitableLine = null,
        public ?string $barcode = null,
        public ?string $statusCode = null,
        public ?string $statusMessage = null,
        public array $payload = [],
        public array $errors = [],
        public ?string $message = null,
    ) {}
}
