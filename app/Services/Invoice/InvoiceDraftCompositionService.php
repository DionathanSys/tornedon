<?php

namespace App\Services\Invoice;

use App\Enum\Invoice\InvoiceAction;
use App\Enum\ProductionOrder\Status as ProductionStatus;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Enum\ServiceOrder\State as ServiceOrderState;
use App\Models\Invoice;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceDraftCompositionService
{
    public function remove(Invoice $invoice, Requisition|ServiceOrder $source, int $userId): void
    {
        DB::transaction(function () use ($invoice, $source, $userId): void {
            $invoice = Invoice::where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($invoice->id);
            $user = User::findOrFail($userId);
            abort_unless($user->belongsToCompany($invoice->company_id), 403);
            app(InvoiceActionEligibilityService::class)->ensureAllowed($invoice, InvoiceAction::EDIT_COMPOSITION);
            $source = $source->newQuery()->where('company_id', $invoice->company_id)->where('invoice_id', $invoice->id)->lockForUpdate()->findOrFail($source->id);
            $this->release($invoice, $source, $userId);
        });
        $invoice->refresh();
    }

    private function release(Invoice $invoice, Requisition|ServiceOrder|ProductionOrder $source, int $userId): void
    {
        $type = match (true) {
            $source instanceof Requisition => 'requisition',
            $source instanceof ServiceOrder => 'service_order',
            default => 'production_order',
        };
        if ($source instanceof Requisition && $source->production_order_id) {
            $production = ProductionOrder::where('company_id', $invoice->company_id)->where('invoice_id', $invoice->id)->lockForUpdate()->find($source->production_order_id);
            if ($production) {
                $this->release($invoice, $production, $userId);
            }
        }
        if ($source instanceof ServiceOrder) {
            foreach ($invoice->requisitions()->where('company_id', $invoice->company_id)->where('service_order_id', $source->id)->lockForUpdate()->get() as $requisition) {
                $this->release($invoice, $requisition, $userId);
            }
        }
        if ((int) $source->customer_id !== (int) $invoice->customer_id) {
            throw ValidationException::withMessages(['invoice' => 'A ordem deve pertencer ao cliente da fatura.']);
        }
        app(InvoiceCompositionService::class)->recordDraftRemoval($invoice, $source, $type, $userId);
        $data = [
            'invoice_id' => null,
            'status' => match ($type) {
                'requisition' => RequisitionStatus::CLOSED,
                'service_order' => ServiceOrderState::CLOSED,
                default => ProductionStatus::COMPLETED,
            },
            'updated_by' => $userId,
        ];
        if ($source instanceof Requisition) {
            $data += ['invoiced_at' => null, 'status_before_invoicing' => null];
        }
        $source->update($data);
        app(AuditRecorder::class)->recordModelEvent($invoice, 'invoice.source_removed', 'Ordem removida do rascunho e liberada para faturamento', actorUserId: $userId, metadata: ['source_type' => $type, 'source_id' => $source->id]);
    }
}
