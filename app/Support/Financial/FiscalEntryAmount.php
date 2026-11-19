<?php

namespace App\Support\Financial;

use App\Models\FiscalDocument;

final class FiscalEntryAmount
{
    public static function fromParsedXml(array $parsed, ?float $fallback = null): float
    {
        return round((float) (data_get($parsed, 'totals.ICMSTot.vNF') ?? $fallback ?? 0), 2);
    }

    public static function fromDocument(FiscalDocument $document): float
    {
        $xmlTotal = data_get($document->tax_data, 'totals.ICMSTot.vNF');
        if ($xmlTotal !== null) {
            return round((float) $xmlTotal, 2);
        }

        $document->loadMissing('items');

        return round($document->items->where('included_in_total', '!=', false)->sum(fn ($item): float => (float) $item->total_price - (float) $item->discount_amount
            + (float) $item->freight_amount + (float) $item->insurance_amount + (float) $item->other_expenses_amount
        ), 2);
    }
}
