<?php

namespace App\Services\Financial\BankStatement;

use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SearchBankReconciliationCandidates
{
    public function search(int $companyId, Collection $lines, string $type, string $search = '', int $page = 1): array
    {
        $first = $lines->first();
        if (! $first || $lines->contains(fn ($line) => (int) $line->company_id !== $companyId)) {
            throw ValidationException::withMessages(['selection' => 'Selecione lançamentos da empresa atual.']);
        }
        $installmentType = $first->isInflow() ? 'receivable' : 'payable';
        if (! in_array($type, ['cash_movement', $installmentType], true)) {
            throw ValidationException::withMessages(['selection' => 'Tipo de registro incompatível com o extrato.']);
        }

        $movement = $type === 'cash_movement';
        $model = $movement ? CashMovement::class : ($type === 'receivable' ? AccountReceivableInstallment::class : AccountPayableInstallment::class);
        $amountColumn = $movement ? 'amount' : 'balance_amount';
        $dateColumn = $movement ? 'transaction_date' : 'due_date';
        $parent = $type === 'receivable' ? 'accountReceivable' : 'accountPayable';
        $party = $type === 'receivable' ? 'customer' : 'supplier';
        $query = $model::query()->where('company_id', $companyId);
        if ($movement) {
            $query->where('financial_account_id', $first->financial_account_id)
                ->where('direction', $first->direction()?->value)
                ->whereNull('reversed_at')->whereNull('reversal_of_id')->unreconciled();
        } else {
            $query->with($parent.'.'.$party)->where('balance_amount', '>', 0)->where('status', '!=', 'cancelled')
                ->whereHas($parent, fn (Builder $query) => $query->where('company_id', $companyId)->where('status', '!=', 'cancelled'));
        }

        $search = mb_substr(trim($search), 0, 150);
        if ($search !== '') {
            $query->where(function (Builder $query) use ($search, $movement, $parent, $party, $amountColumn) {
                $query->where('description', 'like', '%'.$search.'%');
                if (ctype_digit(ltrim($search, '#'))) {
                    $query->orWhere('id', (int) ltrim($search, '#'));
                }
                $amount = $this->searchAmount($search);
                if ($amount !== null) {
                    $query->orWhere($amountColumn, $amount);
                }
                if ($movement) {
                    $query->orWhere('manual_counterparty_name', 'like', '%'.$search.'%')
                        ->orWhere('participants_snapshot->counterparty_partner_name', 'like', '%'.$search.'%')
                        ->orWhereIn('counterparty_partner_id', Partner::query()->select('id')->where('name', 'like', '%'.$search.'%'));
                } else {
                    $query->orWhereHas($parent, fn (Builder $query) => $query
                        ->where('manual_counterparty_name', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%')
                        ->orWhereHas($party, fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%')));
                }
            });
        }

        $total = (int) $lines->sum(fn ($line) => round((float) $line->amount * 100));
        $page = max(1, min($page, 10000));
        $results = $query->orderByRaw('ABS('.$amountColumn.' - ?)', [$total])
            ->orderBy($dateColumn)->orderBy('id')->offset(($page - 1) * 20)->limit(21)->get();
        $tolerance = max(0, (int) ($first->financialAccount?->reconciliation_date_tolerance_days ?? 3));

        return [
            'has_more' => $results->count() > 20,
            'records' => $results->take(20)->map(function ($record) use ($movement, $parent, $amountColumn, $dateColumn, $lines, $tolerance, $total): array {
                $amount = (int) round((float) $record->{$amountColumn} * 100);
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
            })->values()->all(),
        ];
    }

    private function searchAmount(string $search): ?int
    {
        $value = trim(str_replace(['R$', ' '], '', $search));
        if (str_contains($value, ',')) {
            $value = str_replace(',', '.', str_replace('.', '', $value));
        }
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            return null;
        }

        return (int) round((float) $value * 100);
    }
}
