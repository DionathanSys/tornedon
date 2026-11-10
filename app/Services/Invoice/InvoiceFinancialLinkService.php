<?php

namespace App\Services\Invoice;

use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CashMovement;
use App\Models\Invoice;
use App\Models\InvoiceReceivableLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceFinancialLinkService
{
    public function recordGenerated(AccountReceivable $receivable, ?int $userId): void
    {
        if (! $receivable->invoice_id) {
            return;
        }
        $invoice = Invoice::where('company_id', $receivable->company_id)->findOrFail($receivable->invoice_id);
        $this->record($invoice, $receivable, 'generated', ['invoice_id' => null, 'fiscal_document_id' => null], $userId);
    }

    public function attachExisting(Invoice $invoice, AccountReceivable $receivable, int $userId, int $documentId): void
    {
        DB::transaction(function () use ($invoice, $receivable, $userId, $documentId): void {
            $invoice = Invoice::where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($invoice->id);
            $receivable = AccountReceivable::where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($receivable->id);
            if ($receivable->invoice_id || $receivable->fiscal_document_id || (int) $receivable->customer_id !== (int) $invoice->customer_id || $invoice->canceled || $invoice->operations()->where('active_slot', 1)->exists()
                || ! $invoice->fiscalDocuments()->where('company_id', $invoice->company_id)->whereKey($documentId)->exists()) {
                throw ValidationException::withMessages(['receivable' => 'O título não está disponível para associação a esta fatura.']);
            }
            $this->record($invoice, $receivable, 'linked_existing', $receivable->only(['invoice_id', 'fiscal_document_id']), $userId);
            $receivable->update(['invoice_id' => $invoice->id, 'fiscal_document_id' => $documentId]);
        });
    }

    private function record(Invoice $invoice, AccountReceivable $receivable, string $origin, array $original, ?int $userId): void
    {
        $invoice->receivableLinks()->create([
            'company_id' => $invoice->company_id, 'account_receivable_id' => $receivable->id,
            'source_receivable_id' => $receivable->id, 'origin' => $origin, 'original_links' => $original,
            'snapshot' => $this->snapshot($receivable), 'active_slot' => 1, 'linked_at' => now(), 'linked_by' => $userId ?: null,
        ]);
    }

    public function release(InvoiceReceivableLink $link, string $reason): void
    {
        if ($link->released_at) {
            return;
        }
        $link->update(['active_slot' => null, 'released_at' => now(), 'release_reason' => $reason,
            'release_snapshot' => $link->accountReceivable ? $this->snapshot($link->accountReceivable) : null]);
    }

    public function snapshot(AccountReceivable $receivable): array
    {
        $receivable = $receivable->fresh(['installments.payments']);
        $payments = $receivable->installments->flatMap->payments;
        $movements = CashMovement::where('company_id', $receivable->company_id)
            ->where('origin_type', AccountReceivableInstallmentPayment::class)->whereIn('origin_id', $payments->pluck('id'))->with('reversals')->get();

        return [
            'id' => $receivable->id, 'document_number' => $receivable->document_number,
            'due_amount_cents' => (int) round($receivable->due_amount * 100),
            'invoice_id' => $receivable->invoice_id, 'fiscal_document_id' => $receivable->fiscal_document_id,
            'installments' => $receivable->installments->map(fn ($installment) => ['id' => $installment->id, 'due_date' => $installment->due_date?->toDateString(), 'amount_cents' => (int) round($installment->due_amount * 100)])->all(),
            'payments' => $payments->map(fn ($payment) => ['id' => $payment->id, 'installment_id' => $payment->account_receivable_installment_id, 'amount_cents' => (int) round($payment->amount * 100), 'returned_cents' => (int) round($payment->returned_amount * 100), 'bank_confirmed_at' => $payment->bank_confirmed_at?->toIso8601String()])->values()->all(),
            'movements' => $movements->map(fn ($movement) => ['id' => $movement->id, 'payment_id' => $movement->origin_id, 'amount_cents' => (int) round($movement->amount * 100), 'reversed_at' => $movement->reversed_at?->toIso8601String(), 'reversals' => $movement->reversals->map(fn ($reversal) => ['id' => $reversal->id, 'reversal_of_id' => $reversal->reversal_of_id, 'amount_cents' => (int) round($reversal->amount * 100)])->all()])->all(),
        ];
    }
}
