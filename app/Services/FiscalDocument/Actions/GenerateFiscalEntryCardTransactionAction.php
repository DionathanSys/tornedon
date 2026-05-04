<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\Payment\Condition;
use App\Models\FiscalDocument;
use App\Services\CompanyCard\CompanyCardTransactionService;

class GenerateFiscalEntryCardTransactionAction
{
    public function __construct(
        private readonly CompanyCardTransactionService $companyCardTransactionService = new CompanyCardTransactionService(),
    ) {}

    /**
     * @param  array{
     *     company_credit_card_id: int|string,
     *     payment_condition: string,
     *     card_transaction_date?: ?string,
     *     description?: ?string,
     * } $paymentData
     * @return array{transactions: int, errors: string[]}
     */
    public function execute(FiscalDocument $document, array $paymentData, int $userId): array
    {
        $document->loadMissing('items');

        $condition = Condition::from((string) $paymentData['payment_condition']);
        $totalAmount = round((float) $document->items->sum(fn ($item) => (float) $item->total_price), 2);

        if ($totalAmount <= 0) {
            return [
                'transactions' => 0,
                'errors' => ['Não foi possível registrar cartão: valor total da nota é inválido.'],
            ];
        }

        $installments = max(1, $condition->installments() ?: 1);
        $transactionDate = $paymentData['card_transaction_date']
            ?? $document->movement_at?->toDateString()
            ?? $document->issued_at?->toDateString()
            ?? now()->toDateString();

        $description = $paymentData['description']
            ?? sprintf('Compra em cartão - NF #%s', $document->document_number ?? $document->id);

        $created = $this->companyCardTransactionService->createFromFiscalDocument(
            $document,
            [
                'company_credit_card_id' => (int) $paymentData['company_credit_card_id'],
                'transaction_date' => $transactionDate,
                'description' => $description,
                'amount' => $totalAmount,
                'installments' => $installments,
                'source_description' => sprintf('NF %s', $document->document_number ?? $document->id),
            ],
            $userId,
        );

        if ($this->companyCardTransactionService->hasError() || $created === null) {
            return [
                'transactions' => 0,
                'errors' => [
                    'Erro ao registrar transação no cartão corporativo: ' . $this->companyCardTransactionService->getMessageUser(),
                ],
            ];
        }

        return [
            'transactions' => count($created),
            'errors' => [],
        ];
    }
}
