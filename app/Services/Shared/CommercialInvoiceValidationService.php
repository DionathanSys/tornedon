<?php

namespace App\Services\Shared;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;

class CommercialInvoiceValidationService
{
    public function validateForNewInvoice(
        Collection $records,
        string $sameCustomerMessage,
        string $closedStatusMessage,
        callable $isClosed,
        callable $emptyItemsMessage,
    ): ?string {
        $customerIds = $records->pluck('customer_id')->unique();
        if ($customerIds->count() > 1) {
            return $sameCustomerMessage;
        }

        $notClosed = $records->filter(fn ($record) => ! $isClosed($record));
        if ($notClosed->isNotEmpty()) {
            return $closedStatusMessage;
        }

        foreach ($records as $record) {
            if ($record->items()->count() === 0) {
                return $emptyItemsMessage($record);
            }
        }

        return null;
    }

    public function validateForExistingInvoice(
        Collection $records,
        Invoice $invoice,
        string $sameCustomerMessage,
        string $sameCompanyMessage,
        string $closedStatusMessage,
        callable $isClosed,
        callable $alreadyInvoicedMessage,
        callable $emptyItemsMessage,
    ): ?string {
        if ($records->isEmpty()) {
            return 'Nenhum registro informado para faturamento.';
        }

        $customerIds = $records->pluck('customer_id')->push($invoice->customer_id)->unique();
        if ($customerIds->count() > 1) {
            return $sameCustomerMessage;
        }

        if ((int) $invoice->company_id !== (int) $records->first()->company_id) {
            return $sameCompanyMessage;
        }

        $notClosed = $records->filter(fn ($record) => ! $isClosed($record));
        if ($notClosed->isNotEmpty()) {
            return $closedStatusMessage;
        }

        foreach ($records as $record) {
            if ($record->invoice_id !== null) {
                return $alreadyInvoicedMessage($record);
            }

            if ($record->items()->count() === 0) {
                return $emptyItemsMessage($record);
            }
        }

        return null;
    }
}
