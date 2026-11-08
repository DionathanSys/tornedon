<?php

namespace App\Services\Invoice;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\Status as FiscalStatus;
use App\Enum\Invoice\Status;
use App\Models\Invoice;

class InvoiceFiscalDocumentEligibilityService
{
    public function hasBlockingDocument(Invoice $invoice, DocumentModel $type): bool
    {
        $statusField = $type === DocumentModel::NFSE ? 'nfse_status' : 'nfe_status';

        return $invoice->fiscalDocuments()->where('company_id', $invoice->company_id)->where('document_type', $type->value)
            ->where(function ($query) use ($statusField): void {
                $query->where('status', '!=', FiscalStatus::CANCELLED->value)
                    ->orWhereNull('status')
                    ->orWhere(fn ($query) => $query->whereNotNull($statusField)->where($statusField, '!=', NfeStatus::CANCELED->value))
                    ->orWhereHas('fiscalIssues', fn ($query) => $query->where('active_slot', 1));
            })->exists();
    }

    public function evaluate(Invoice $invoice, DocumentModel $type): array
    {
        if ($invoice->status !== Status::CONFIRMED || ! $invoice->confirmed || $invoice->canceled) {
            return ['allowed' => false, 'message' => 'A emissão exige uma fatura confirmada.'];
        }
        if ($invoice->operations()->where('active_slot', 1)->exists()) {
            return ['allowed' => false, 'message' => 'Existe uma operação em andamento na fatura.'];
        }
        if ($this->hasBlockingDocument($invoice, $type)) {
            return ['allowed' => false, 'message' => 'Existe uma nota deste tipo ainda ativa ou com tratamento fiscal pendente.'];
        }

        return ['allowed' => true, 'message' => null];
    }
}
