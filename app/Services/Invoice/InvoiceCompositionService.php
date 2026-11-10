<?php

namespace App\Services\Invoice;

use App\Enum\Invoice\Status;
use App\Models\Invoice;
use App\Models\InvoiceConfirmation;
use App\Models\InvoiceSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceCompositionService
{
    public function capture(Invoice $invoice, int $userId): InvoiceConfirmation
    {
        return DB::transaction(function () use ($invoice, $userId): InvoiceConfirmation {
            $record = Invoice::where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($invoice->id);
            if ($record->status !== Status::PENDING || $record->confirmed || $record->canceled) {
                throw ValidationException::withMessages(['invoice' => 'Somente uma confirmação de rascunho pode registrar uma nova composição.']);
            }

            $sources = [];
            foreach (['requisitions' => 'requisition', 'serviceOrders' => 'service_order', 'productionOrders' => 'production_order'] as $relation => $type) {
                foreach ($record->{$relation}()->lockForUpdate()->get() as $source) {
                    if ((int) $source->company_id !== (int) $record->company_id || (int) $source->customer_id !== (int) $record->customer_id) {
                        throw ValidationException::withMessages(['invoice' => 'Todas as ordens devem pertencer à empresa e ao cliente da fatura.']);
                    }
                    $source->items()->lockForUpdate()->get();
                    $sources[] = ['source' => $source, 'type' => $type, 'snapshot' => $this->describe($source, $type)];
                }
            }

            $amounts = [];
            foreach (['gross_amount', 'discount_amount', 'services_amount', 'products_amount', 'total_amount', 'net_value'] as $key) {
                $amounts[$key] = $this->cents($record->{$key});
            }
            $confirmation = $record->confirmations()->create([
                'company_id' => $record->company_id,
                'revision' => ((int) $record->confirmations()->max('revision')) + 1,
                'amounts' => $amounts,
                'commercial_data' => [
                    'customer_id' => $record->customer_id,
                    'customer_name' => $record->customer?->name,
                    'company_name' => $record->company?->name,
                    'invoice_number' => $record->invoice_number,
                    'invoice_date' => $record->invoice_date?->toDateString(),
                    'payment_method' => $record->payment_method?->value,
                    'payment_condition' => $record->payment_condition?->value,
                    'payment_condition_details' => $record->payment_condition_details,
                    'financial_policy' => $record->financial_policy,
                    'financial_category_id' => $record->financial_category_id,
                ],
                'confirmed_by' => $userId,
                'confirmed_at' => now(),
            ]);
            foreach ($sources as $entry) {
                $confirmation->sources()->create([
                    'company_id' => $record->company_id,
                    'invoice_id' => $record->id,
                    'source_type' => $entry['type'],
                    'source_id' => $entry['source']->id,
                    'source_number' => $entry['snapshot']['number'],
                    'snapshot' => $entry['snapshot'],
                    'linked_at' => now(),
                ]);
            }

            return $confirmation;
        });
    }

    /** Values in snapshots are integer cents; quantities remain decimal strings. */
    public function describe(Model $source, string $type): array
    {
        $catalog = $type === 'service_order' ? 'service' : 'product';
        $source->load('items.'.$catalog);
        $production = $type === 'production_order';
        if ($production) {
            $source->load('items.quoteItem');
        }
        $items = $source->items->map(function ($item) use ($catalog, $production): array {
            $quantity = $production ? ($item->quantity_approved ?: $item->quantity_produced ?: $item->quantity) : $item->quantity;
            $unitPrice = $production ? ($item->quoteItem?->unit_price ?? 0) : $item->unit_price;

            return [
                'source_item_id' => $item->id,
                'stock_control_snapshot' => $item->stock_control_snapshot,
                'catalog_id' => $item->{$catalog.'_id'},
                'description' => $item->{$catalog}?->name ?? '-',
                'observations' => $item->observations,
                'quantity' => (string) $quantity,
                'unit_of_measure' => $item->unit_of_measure instanceof \BackedEnum ? $item->unit_of_measure->value : $item->unit_of_measure,
                'unit_price' => $this->cents($unitPrice),
                'gross_amount' => $this->cents($production ? (float) $quantity * (float) $unitPrice : $item->gross_amount),
                'discount_amount' => $this->cents($production ? 0 : $item->discount_amount),
                'total_amount' => $this->cents($production ? (float) $quantity * (float) $unitPrice : $item->total_amount),
            ];
        })->all();

        return [
            'number' => (string) ($production ? $source->production_order_number : $source->number),
            'status' => $source->status?->description(),
            'items' => $items,
            'travel_amount' => $this->cents($type === 'service_order' ? $source->travel_value : 0),
            // OP values are informational: the requisition already supplies financial totals.
            'contributes_to_total' => ! $production,
            'total_amount' => $production ? array_sum(array_column($items, 'total_amount')) : $this->cents($source->total_amount),
        ];
    }

    public function recordDraftRemoval(Invoice $invoice, Model $source, string $type, int $userId): InvoiceSource
    {
        $snapshot = $this->describe($source, $type);

        return $invoice->sources()->create([
            'company_id' => $invoice->company_id,
            'source_type' => $type,
            'source_id' => $source->id,
            'source_number' => $snapshot['number'],
            'snapshot' => $snapshot,
            'linked_at' => now(),
            'released_at' => now(),
            'release_reason' => 'draft_removal',
            'released_by' => $userId,
        ]);
    }

    private function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }
}
