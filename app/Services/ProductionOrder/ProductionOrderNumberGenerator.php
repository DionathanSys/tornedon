<?php

namespace App\Services\ProductionOrder;

use App\Models\ProductionOrderSequence;
use Illuminate\Support\Facades\DB;

class ProductionOrderNumberGenerator
{
    /**
     * Gera o próximo número de ordem de produção para a empresa informada.
     * Formato: PRD-{YEAR}-{SEQUENCE}
     * Exemplo: PRD-2026-0001
     */
    public static function generate(int $companyId): string
    {
        return DB::transaction(function () use ($companyId) {
            $sequence = ProductionOrderSequence::lockForUpdate()
                ->firstOrCreate(
                    ['company_id' => $companyId],
                    ['last_number' => 0]
                );

            $sequence->increment('last_number');

            $year = now()->year;
            $number = str_pad($sequence->last_number, 4, '0', STR_PAD_LEFT);

            return "PRD-{$year}-{$number}";
        });
    }
}
