<?php

namespace App\Services\Financial\BankStatement\Contracts;

use App\Domain\DTO\Financial\OfxStatementHeaderDTO;
use App\Domain\DTO\Financial\OfxTransactionDTO;

interface BankOfxNormalizerInterface
{
    public function supports(OfxStatementHeaderDTO $header): bool;

    public function institutionName(): string;

    public function normalizeHeader(OfxStatementHeaderDTO $header): OfxStatementHeaderDTO;

    public function normalizeTransaction(OfxTransactionDTO $transaction): OfxTransactionDTO;
}
