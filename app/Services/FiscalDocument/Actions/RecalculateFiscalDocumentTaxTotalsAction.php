<?php

namespace App\Services\FiscalDocument\Actions;

use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;

class RecalculateFiscalDocumentTaxTotalsAction
{
    public function execute(FiscalDocument $document): FiscalDocument
    {
        $document->loadMissing('items');

        $totals = [
            'valor_produtos' => 0.0,
            'valor_nota' => 0.0,
            'valor_desconto' => 0.0,
            'valor_frete' => 0.0,
            'valor_seguro' => 0.0,
            'valor_outras_despesas' => 0.0,
            'base_calculo_icms' => 0.0,
            'valor_icms' => 0.0,
            'base_calculo_icms_st' => 0.0,
            'valor_icms_st' => 0.0,
            'valor_ipi' => 0.0,
            'valor_pis' => 0.0,
            'valor_cofins' => 0.0,
        ];

        foreach ($document->items as $item) {
            /** @var FiscalDocumentItem $item */
            $taxData = is_array($item->tax_data) ? $item->tax_data : [];

            $grossAmount = (float) ($item->total_price ?? 0);
            $discountAmount = (float) ($item->discount_amount ?? 0);
            $freightAmount = (float) ($item->freight_amount ?? 0);
            $insuranceAmount = (float) ($item->insurance_amount ?? 0);
            $otherExpensesAmount = (float) ($item->other_expenses_amount ?? 0);

            $totals['valor_produtos'] += $grossAmount;
            $totals['valor_desconto'] += $discountAmount;
            $totals['valor_frete'] += $freightAmount;
            $totals['valor_seguro'] += $insuranceAmount;
            $totals['valor_outras_despesas'] += $otherExpensesAmount;
            $totals['base_calculo_icms'] += (float) data_get($taxData, 'imposto.icms.valor_base_calculo', 0);
            $totals['valor_icms'] += (float) data_get($taxData, 'imposto.icms.valor', 0);
            $totals['base_calculo_icms_st'] += (float) data_get($taxData, 'imposto.icms.valor_base_calculo_st', 0);
            $totals['valor_icms_st'] += (float) data_get($taxData, 'imposto.icms.valor_st', 0);
            $totals['valor_ipi'] += (float) data_get($taxData, 'imposto.ipi.valor', 0);
            $totals['valor_pis'] += (float) data_get($taxData, 'imposto.pis.valor', 0);
            $totals['valor_cofins'] += (float) data_get($taxData, 'imposto.cofins.valor', 0);
        }

        $totals['valor_nota'] = round(
            $totals['valor_produtos']
            - $totals['valor_desconto']
            + $totals['valor_frete']
            + $totals['valor_seguro']
            + $totals['valor_outras_despesas'],
            2
        );

        $totals = collect($totals)
            ->map(fn (float $value): string => number_format(round($value, 2), 2, '.', ''))
            ->all();

        $taxData = is_array($document->tax_data) ? $document->tax_data : [];
        $taxData['totais'] = $totals;

        $document->forceFill([
            'tax_data' => $taxData,
        ])->save();

        return $document->fresh();
    }
}
