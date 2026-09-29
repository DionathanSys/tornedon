<?php

namespace App\Services\Financial\Banking\DTO;

final readonly class BankSlipWebhookEvent
{
    public function __construct(
        public string $providerEventId,
        public ?string $eventType,
        public ?string $identification,
        public ?string $statusCode,
        public ?string $statusMessage,
        public ?float $amount,
        public array $payload,
    ) {}
}
