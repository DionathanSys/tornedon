<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\AccountPayable\Status as AccountPayableStatus;
use App\Enum\Payment\Condition;
use App\Models\FiscalDocument;
use App\Services\AccountPayable\AccountPayableService;

class GenerateFiscalEntryPayableAction
{
    public function __construct(
        private readonly AccountPayableService $accountPayableService = new AccountPayableService(),
    ) {}

    /**
     * @param  array{
     *     payment_method: string,
     *     payment_condition: string,
     *     due_date: string,
     *     description: ?string,
     * } $paymentData
     * @return array{payables: int, errors: string[]}
     */
    public function execute(FiscalDocument $document, array $paymentData, int $userId): array
    {
        $document->loadMissing('items');

        $condition = Condition::from($paymentData['payment_condition']);
        $totalAmount = $document->items->sum(fn ($item) => (float) $item->total_price);
        $description = $paymentData['description'] ?? "NF #{$document->document_number}";
        $result = [
            'payables' => 0,
            'errors' => [],
        ];

        $payload = [
            'supplier_id' => $document->customer_id,
            'company_id' => $document->company_id,
            'fiscal_document_id' => $document->id,
            'sequence_number' => '01',
            'status' => AccountPayableStatus::PENDING->value,
            'payment_method' => $paymentData['payment_method'],
            'due_date' => $paymentData['due_date'],
            'due_amount' => $totalAmount,
            'description' => $description,
            'document_number' => $document->document_number,
        ];

        $installmentCount = $condition->installments();

        if ($installmentCount > 1) {
            $payload['installment_count'] = $installmentCount;

            if ($condition->isTerm()) {
                $payload['installment_due_mode'] = $condition->value;
            }
        }

        $payable = $this->accountPayableService->create($payload, $userId);

        if ($this->accountPayableService->hasError() || ! $payable) {
            $result['errors'][] = 'Erro ao criar conta a pagar: '
                . $this->accountPayableService->getMessageUser();

            return $result;
        }

        $result['payables'] = 1;

        return $result;
    }
}
