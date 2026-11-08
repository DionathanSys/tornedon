<?php

namespace App\Models\Concerns;

use App\Enum\Invoice\Status;
use App\Models\Invoice;
use Illuminate\Validation\ValidationException;

trait ProtectsInvoiceComposition
{
    public static function bootProtectsInvoiceComposition(): void
    {
        static::saving(function ($source): void {
            if ($source->isDirty('invoice_id')) {
                $oldId = $source->getRawOriginal('invoice_id');
                if ($oldId && $source->invoice_id && (int) $oldId !== (int) $source->invoice_id) {
                    throw ValidationException::withMessages(['invoice' => 'Libere o vínculo atual antes de faturar a ordem novamente.']);
                }
                if ($oldId && Invoice::whereKey($oldId)->where('status', Status::CONFIRMED->value)->exists()) {
                    throw ValidationException::withMessages(['invoice' => 'Reverta o faturamento antes de liberar uma ordem confirmada.']);
                }
                if ($source->invoice_id) {
                    $invoice = Invoice::whereKey($source->invoice_id)->where('company_id', $source->company_id)->where('customer_id', $source->customer_id)->first();
                    if (! $invoice || $invoice->status !== Status::PENDING || $invoice->confirmed || $invoice->canceled || $invoice->operations()->where('active_slot', 1)->exists()) {
                        throw ValidationException::withMessages(['invoice' => 'A ordem só pode ser vinculada a um rascunho disponível da mesma empresa e cliente.']);
                    }
                }
            }
            if ($source->exists && $source->getRawOriginal('invoice_id') && $source->isDirty(['company_id', 'customer_id', 'travel_value'])) {
                $invoice = Invoice::find($source->getRawOriginal('invoice_id'));
                if ($invoice?->status === Status::CONFIRMED) {
                    throw ValidationException::withMessages(['invoice' => 'A composição comercial de uma fatura confirmada está congelada.']);
                }
            }
        });
    }
}
