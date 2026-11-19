<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments;

use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use App\Models\AccountPayable;
use App\Models\SefazDistributionDocument;
use Illuminate\Support\Collection;

final class PayableSummary
{
    public static function accounts(SefazDistributionDocument $document): Collection
    {
        return collect([$document->accountPayable])->merge($document->fiscalDocument?->accountPayables ?? [])
            ->filter(fn (?AccountPayable $account): bool => $account !== null
                && (int) $account->company_id === (int) $document->company_id && AccountPayableResource::canView($account))
            ->unique('id')->values();
    }

    public static function installments(SefazDistributionDocument $document): Collection
    {
        return self::accounts($document)->flatMap(fn (AccountPayable $account) => $account->installments)
            ->filter(fn ($row): bool => (int) $row->company_id === (int) $document->company_id
                && ! $row->ignored_at && $row->status?->value !== 'cancelled');
    }

    public static function balance(SefazDistributionDocument $document): float
    {
        return round((float) self::installments($document)->sum('balance_amount'), 2);
    }

    public static function status(SefazDistributionDocument $document): string
    {
        if (self::accounts($document)->isEmpty()) {
            return 'Sem financeiro';
        }
        $installments = self::installments($document);
        if ($installments->isEmpty()) {
            return 'Sem parcelas consideradas';
        }
        if (self::balance($document) <= 0) {
            return 'Quitado';
        }

        return $installments->sum('paid_amount') > 0 ? 'Parcialmente pago' : 'Em aberto';
    }

    public static function nextDueDate(SefazDistributionDocument $document): mixed
    {
        return self::installments($document)->filter(fn ($row): bool => (float) $row->balance_amount > 0)->min('due_date');
    }
}
