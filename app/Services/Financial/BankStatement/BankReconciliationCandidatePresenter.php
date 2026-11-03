<?php

namespace App\Services\Financial\BankStatement;

use App\Models\BankStatementLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class BankReconciliationCandidatePresenter
{
    public function present(Model $record, Collection $lines, string $type): array
    {
        $movement = $type === 'cash_movement';
        $parent = $type === 'receivable' ? 'accountReceivable' : 'accountPayable';
        $amountColumn = $movement ? 'amount' : 'balance_amount';
        $dateColumn = $movement ? 'transaction_date' : 'due_date';
        $total = (int) $lines->sum(fn (BankStatementLine $line) => round((float) $line->amount * 100));
        $amount = (int) round((float) $record->{$amountColumn} * 100);
        $tolerance = max(0, (int) ($lines->first()->financialAccount?->reconciliation_date_tolerance_days ?? 3));
        $dateException = $movement && $lines->contains(fn (BankStatementLine $line) => abs($line->transaction_date->diffInDays($record->transaction_date)) > $tolerance);
        $counterparty = $movement ? $record->counterparty_label : $record->{$parent}?->counterparty_label;

        return [
            'id' => $record->id,
            'description' => $record->description ?: ($movement ? 'Sem descrição' : ($record->{$parent}?->description ?: 'Sem descrição')),
            'party' => $counterparty === 'Não informado' ? null : $counterparty,
            'date' => $record->{$dateColumn}?->format('d/m/Y'),
            'amount_cents' => $amount,
            'exact_match' => $amount === $total,
            'date_exception' => $dateException,
        ];
    }
}
