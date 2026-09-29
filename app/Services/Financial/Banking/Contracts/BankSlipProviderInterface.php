<?php

namespace App\Services\Financial\Banking\Contracts;

use App\Models\BankAccountConnection;
use App\Models\BankSlip;
use App\Services\Financial\Banking\DTO\BankSlipProviderResult;
use App\Services\Financial\Banking\DTO\BankSlipWebhookEvent;

interface BankSlipProviderInterface
{
    public function register(BankSlip $bankSlip): BankSlipProviderResult;

    public function update(BankSlip $bankSlip): BankSlipProviderResult;

    public function cancel(BankSlip $bankSlip): BankSlipProviderResult;

    public function query(BankSlip $bankSlip): BankSlipProviderResult;

    public function validateWebhook(BankAccountConnection $connection, array $payload, string $rawBody): bool;

    public function normalizeWebhook(array $payload): BankSlipWebhookEvent;
}
