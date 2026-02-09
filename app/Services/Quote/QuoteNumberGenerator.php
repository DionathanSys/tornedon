<?php

namespace App\Services\Quote;

use App\Models\QuoteSequence;
use Illuminate\Support\Facades\DB;

class QuoteNumberGenerator
{
    /**
     * Gera o próximo número de orçamento para a empresa informada.
     * Formato: ORC-{YEAR}-{SEQUENCE}
     * Exemplo: ORC-2026-0001
     */
    public static function generate(int $companyId): string
    {
        return DB::transaction(function () use ($companyId) {
            $sequence = QuoteSequence::lockForUpdate()
                ->firstOrCreate(
                    ['company_id' => $companyId],
                    ['last_number' => 0]
                );

            $sequence->increment('last_number');

            $year = now()->year;
            $number = str_pad($sequence->last_number, 4, '0', STR_PAD_LEFT);

            return "ORC-{$year}-{$number}";
        });
    }
}
