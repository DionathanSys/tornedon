<?php

namespace App\Support\Fiscal;

class FiscalItemAmounts
{
    public static function grossTotal(mixed $quantity, mixed $unitPrice): float
    {
        return round((float) $quantity * (float) $unitPrice, 2);
    }

    public static function taxableBase(mixed $grossTotal, mixed $discountAmount = 0): float
    {
        return round(max((float) $grossTotal - (float) $discountAmount, 0), 2);
    }
}
