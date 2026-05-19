<?php

namespace App\Services\Shared;

use App\Models\Invoice;
use App\Services\Invoice\InvoiceService;
use RuntimeException;

class CommercialInvoiceCreationService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    public function createFromRecord(object $record, int $userId, array $extraData = []): Invoice
    {
        $invoice = $this->invoiceService->create(array_merge([
            'customer_id' => $record->customer_id,
            'company_id' => $record->company_id,
            'invoice_date' => now()->toDateString(),
        ], $extraData), $userId);

        if ($this->invoiceService->hasError() || ! $invoice) {
            throw new RuntimeException(
                'Falha ao criar fatura: ' . $this->invoiceService->getMessage()
            );
        }

        return $invoice;
    }

    public function errors(): array
    {
        return $this->invoiceService->getErrors();
    }
}
