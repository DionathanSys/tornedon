<?php

namespace App\Services\Financial\BankStatement;

use App\Models\BankStatementLine;
use Carbon\CarbonInterface;

class BankStatementDatePolicy
{
    public function profile(BankStatementLine $line): array
    {
        $bankId = (string) data_get($line->import?->metadata, 'bank_id', '');

        return config('bank_reconciliation.bank_profiles.'.$bankId, []);
    }

    public function windowDays(BankStatementLine $line, bool $movement): int
    {
        $key = $movement ? 'movement_date_window_days' : 'installment_date_window_days';
        $days = max(0, (int) ($this->profile($line)[$key] ?? config('bank_reconciliation.suggestions.'.$key, $movement ? 7 : 60)));

        return $movement ? max($days, (int) ($line->financialAccount?->reconciliation_date_tolerance_days ?? 3)) : $days;
    }

    public function businessDays(BankStatementLine $line, CarbonInterface $date): int
    {
        $holidays = array_unique([...config('bank_reconciliation.holidays', []), ...($this->profile($line)['holidays'] ?? [])]);

        return (int) abs($line->transaction_date->diffInDaysFiltered(
            fn (CarbonInterface $day): bool => ! $day->isWeekend() && ! in_array($day->toDateString(), $holidays, true), $date,
        ));
    }
}
