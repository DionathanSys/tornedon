<?php

namespace App\Services\Financial;

use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use App\Models\CashMovement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class RefreshChartAccountFromCategoryService
{
    /**
     * @param  Collection<int, AccountPayableInstallment|AccountReceivableInstallment|CashMovement>  $records
     * @return array{updated: int, skipped: int}
     */
    public function refresh(Collection $records, ?int $userId = null): array
    {
        return DB::transaction(function () use ($records, $userId): array {
            $records->loadMissing('financialCategory');
            $updated = 0;
            $skipped = 0;

            foreach ($records as $record) {
                $category = $record->financialCategory;

                if (! $category || (int) $category->company_id !== (int) $record->company_id) {
                    $skipped++;

                    continue;
                }

                if ($record->chart_account_id == $category->chart_account_id) {
                    continue;
                }

                $attributes = ['chart_account_id' => $category->chart_account_id];

                if ($record instanceof CashMovement && $userId !== null) {
                    $attributes['updated_by'] = $userId;
                }

                $record->forceFill($attributes)->save();
                $updated++;
            }

            return compact('updated', 'skipped');
        });
    }
}
