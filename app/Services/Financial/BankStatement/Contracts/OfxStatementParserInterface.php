<?php

namespace App\Services\Financial\BankStatement\Contracts;

use App\Domain\DTO\Financial\OfxStatementHeaderDTO;
use App\Domain\DTO\Financial\OfxTransactionDTO;

interface OfxStatementParserInterface
{
    /**
     * @return array{header: OfxStatementHeaderDTO, transactions: array<int, OfxTransactionDTO>}
     */
    public function parse(string $contents): array;
}
