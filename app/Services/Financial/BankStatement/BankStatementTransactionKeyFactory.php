<?php

namespace App\Services\Financial\BankStatement;

use App\Domain\DTO\Financial\OfxTransactionDTO;

class BankStatementTransactionKeyFactory
{
    public function make(OfxTransactionDTO $transaction): string
    {
        if ($externalIdKey = $this->externalIdKey($transaction->externalId)) {
            return $externalIdKey;
        }

        return 'fallback:'.hash('sha256', json_encode([
            'transaction_date' => $transaction->transactionDate,
            'amount' => number_format($transaction->amount, 4, '.', ''),
            'direction' => $transaction->direction->value,
            'document_number' => $this->normalize($transaction->documentNumber),
            'description' => $this->normalize($transaction->description),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function externalIdKey(?string $externalId): ?string
    {
        $externalId = $this->normalize($externalId);

        return $externalId === '' ? null : 'fitid:'.$externalId;
    }

    private function normalize(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }
}
