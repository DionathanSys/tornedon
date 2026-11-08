<?php

namespace App\Services\Invoice;

use App\Enum\Invoice\InvoiceAction;
use App\Enum\Invoice\Status;
use App\Models\Invoice;
use Illuminate\Validation\ValidationException;

class InvoiceActionEligibilityService
{
    /** Domain rules only: callers must also authorize the user and tenant. */
    public function evaluate(Invoice $invoice, InvoiceAction $action): array
    {
        $invoice = Invoice::where('company_id', $invoice->company_id)->findOrFail($invoice->id);
        $blockers = [];
        $add = function (string $code, string $message) use (&$blockers): void {
            $blockers[] = ['code' => $code, 'message' => $message];
        };
        if ($invoice->operations()->where('active_slot', 1)->exists()) {
            $add('active_operation', 'A fatura possui uma operação em andamento.');
        }
        $expected = $action === InvoiceAction::RETURN_TO_DRAFT ? Status::CONFIRMED : Status::PENDING;
        if ($invoice->status !== $expected || $invoice->canceled || ($expected === Status::PENDING && $invoice->confirmed)) {
            $add('invalid_status', $expected === Status::PENDING ? 'Esta ação exige uma fatura em rascunho.' : 'Somente faturas confirmadas podem retornar para rascunho.');
        }
        if ($action === InvoiceAction::CONFIRM && ! $invoice->requisitions()->exists() && ! $invoice->serviceOrders()->exists()) {
            $add('empty_composition', 'A fatura não possui ordens vinculadas para confirmação.');
        }
        if (in_array($action, [InvoiceAction::CONFIRM, InvoiceAction::EDIT_COMPOSITION, InvoiceAction::DELETE], true)) {
            if ($invoice->fiscalDocuments()->exists()) {
                $add('fiscal_documents', 'A fatura possui documentos fiscais; resolva os vínculos antes de alterar o rascunho.');
            }
            if ($invoice->accountReceivables()->exists()) {
                $add('receivables', 'A fatura possui contas a receber vinculadas.');
            }
        }
        if ($action === InvoiceAction::DELETE && ($invoice->confirmations()->exists() || $invoice->sources()->exists() || $invoice->operations()->exists())) {
            $add('historical_records', 'Faturas com histórico devem ser preservadas.');
        }
        if ($action === InvoiceAction::RETURN_TO_DRAFT) {
            if ($invoice->financial_policy !== null) {
                $add('quick_sale', 'Uma venda rápida finalizada exige Reverter Faturamento.');
            }
            foreach ($invoice->fiscalDocuments()->get() as $document) {
                if ($document->isNfeSent() || $document->isNfseSent() || $document->isNfeAuthorized() || $document->isNfseAuthorized() || $document->isNfeCanceled() || $document->isNfseCanceled() || $document->isNfeInProcessing() || $document->isNfseInProcessing()) {
                    $add('fiscal_communication', 'Existe comunicação fiscal iniciada. Utilize Reverter Faturamento quando disponível.');
                    break;
                }
            }
            foreach ($invoice->accountReceivables()->with('installments.bankSlips', 'installments.pixCharges')->get() as $receivable) {
                if ($receivable->paid || $receivable->paid_amount > 0 || $receivable->payments()->exists()) {
                    $add('receipts', 'Existem recebimentos registrados; regularize o financeiro antes de prosseguir.');
                }
                if ($receivable->installments->contains(fn ($installment) => $installment->bankSlips->isNotEmpty() || $installment->pixCharges->isNotEmpty())) {
                    $add('external_charges', 'Existem cobranças externas vinculadas. A reversão exige tratamento específico.');
                }
            }
        }

        return ['allowed' => $blockers === [], 'blockers' => $blockers];
    }

    public function ensureAllowed(Invoice $invoice, InvoiceAction $action): void
    {
        $result = $this->evaluate($invoice, $action);
        if (! $result['allowed']) {
            throw ValidationException::withMessages(['invoice' => array_column($result['blockers'], 'message')]);
        }
    }
}
