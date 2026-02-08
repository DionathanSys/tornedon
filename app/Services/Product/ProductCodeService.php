<?php

namespace App\Services\Product;

use App\Models\ProductSequence;
use Illuminate\Support\Facades\DB;

class ProductCodeService
{
    /**
     * Gera o próximo código de produto para a empresa informada.
     * Usa lock pessimista para evitar duplicidade em concorrência.
     */
    public static function generate(int $companyId): string
    {
        return DB::transaction(function () use ($companyId) {
            $sequence = ProductSequence::lockForUpdate()
                ->firstOrCreate(
                    ['company_id' => $companyId],
                    ['last_number' => 0]
                );

            $sequence->increment('last_number');

            return str_pad($sequence->last_number, 5, '0', STR_PAD_LEFT);
        });
    }
}
