<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasInvoiceSourceDiscount
{
    public static function bootHasInvoiceSourceDiscount(): void
    {
        static::updated(function ($source): void {
            if ($source->wasChanged('invoice_id') && $source->getRawOriginal('invoice_id')) {
                $source->items()->update(['invoice_discount_amount' => 0, 'invoice_discount_share_percentage' => 0]);
                $source->unsetRelation('items');
            }
        });
    }

    protected function invoiceDiscountAmount(): Attribute
    {
        return Attribute::get(fn (): float => $this->relationLoaded('items')
            ? round((float) $this->items->sum('invoice_discount_amount'), 2)
            : round((float) $this->items()->sum('invoice_discount_amount') / 100, 2));
    }

    protected function invoicedNetAmount(): Attribute
    {
        return Attribute::get(fn (): float => round((float) $this->total_amount - $this->invoice_discount_amount, 2));
    }
}
