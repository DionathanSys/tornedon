<?php

namespace App\Models\Concerns;

use App\Enum\Invoice\Status;
use App\Models\Invoice;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\ServiceOrder;
use Illuminate\Validation\ValidationException;

trait ProtectsInvoicedItem
{
    public static function bootProtectsInvoicedItem(): void
    {
        $check = function ($item): void {
            $requisition = $item instanceof RequisitionItem;
            $key = $requisition ? 'requisition_id' : 'service_order_id';
            $model = $requisition ? Requisition::class : ServiceOrder::class;
            foreach (array_unique(array_filter([$item->{$key}, $item->getRawOriginal($key)])) as $id) {
                $source = $model::find($id);
                // Legacy records without a captured composition remain compatible until reviewed.
                if ($source?->invoice_id && Invoice::whereKey($source->invoice_id)->where('status', Status::CONFIRMED->value)->whereHas('confirmations')->exists()) {
                    throw ValidationException::withMessages(['invoice' => 'Os itens de uma fatura confirmada estão congelados. Reverta o faturamento para alterá-los.']);
                }
            }
        };
        static::saving(function ($item) use ($check): void {
            if (! $item->exists || $item->isDirty(['requisition_id', 'service_order_id', 'product_id', 'service_id', 'quantity', 'unit_price', 'discount_amount', 'discount_percentage', 'unit_of_measure', 'quantity_in_base_unit', 'conversion_factor_snapshot'])) {
                $check($item);
            }
        });
        static::deleting($check);
    }
}
