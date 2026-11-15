<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasInvoiceDiscount
{
    protected function invoicedNetAmount(): Attribute
    {
        return Attribute::get(fn (): float => round((float) $this->total_amount - (float) $this->invoice_discount_amount, 2));
    }

    protected function combinedDiscountAmount(): Attribute
    {
        return Attribute::get(fn (): float => round((float) $this->discount_amount + (float) $this->invoice_discount_amount, 2));
    }
}
