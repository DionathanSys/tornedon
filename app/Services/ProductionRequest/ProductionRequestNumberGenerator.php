<?php

namespace App\Services\ProductionRequest;

use App\Models\ProductionRequestSequence;
use Illuminate\Support\Facades\DB;

class ProductionRequestNumberGenerator
{
    public static function generate(int $companyId): string
    {
        return DB::transaction(function () use ($companyId): string {
            $sequence = ProductionRequestSequence::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['company_id' => $companyId],
                    ['last_number' => 0],
                );

            $sequence->increment('last_number');

            return sprintf('PPR-%s-%04d', now()->year, $sequence->last_number);
        });
    }
}
