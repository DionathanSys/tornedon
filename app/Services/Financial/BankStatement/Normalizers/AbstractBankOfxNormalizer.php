<?php

namespace App\Services\Financial\BankStatement\Normalizers;

use App\Domain\DTO\Financial\OfxStatementHeaderDTO;
use App\Domain\DTO\Financial\OfxTransactionDTO;
use App\Services\Financial\BankStatement\Contracts\BankOfxNormalizerInterface;

abstract class AbstractBankOfxNormalizer implements BankOfxNormalizerInterface
{
    abstract protected function supportedBankIds(): array;

    public function supports(OfxStatementHeaderDTO $header): bool
    {
        return in_array($header->bankId, $this->supportedBankIds(), true);
    }

    public function normalizeHeader(OfxStatementHeaderDTO $header): OfxStatementHeaderDTO
    {
        return $header->with(
            branchId: $this->normalizeCode($header->branchId),
            accountId: $this->normalizeCode($header->accountId),
            institutionName: $this->institutionName(),
        );
    }

    public function normalizeTransaction(OfxTransactionDTO $transaction): OfxTransactionDTO
    {
        $description = preg_replace('/\s+/', ' ', trim($transaction->description)) ?: 'Lancamento OFX';

        return $transaction->with(
            description: mb_substr($description, 0, 255),
            documentNumber: $this->normalizeCode($transaction->documentNumber),
        );
    }

    protected function normalizeCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/[^A-Za-z0-9\-]/', '', trim($value));

        return $normalized !== '' ? $normalized : null;
    }
}
