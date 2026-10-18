<?php

namespace App\Console\Commands;

use App\Models\AccountPayableInstallment;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CashMovement;
use Illuminate\Console\Command;

class AuditFinancialIntegrityCommand extends Command
{
    protected $signature = 'financial:audit-integrity {--company= : ID da empresa; omitido verifica todas} {--limit=100 : Máximo de ocorrências exibidas}';

    protected $description = 'Diagnostica inconsistências financeiras sem alterar registros.';

    public function handle(): int
    {
        $companyId = $this->option('company');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (($companyId !== null && (! ctype_digit((string) $companyId) || (int) $companyId < 1)) || $limit === false || $limit < 1) {
            $this->error('Informe uma empresa válida e um limite positivo.');

            return self::INVALID;
        }

        $total = 0;
        $rows = [];
        $report = function (string $issue, $record, string $detail) use (&$total, &$rows, $limit): void {
            $total++;
            if (count($rows) < $limit) {
                $rows[] = [$record->company_id, class_basename($record), $record->id, $issue, $detail];
            }
        };

        foreach ([AccountPayableInstallmentPayment::class, AccountReceivableInstallmentPayment::class] as $model) {
            $model::query()->when($companyId, fn ($query) => $query->where('company_id', (int) $companyId))
                ->chunkById(200, function ($payments) use ($model, $report): void {
                    $movements = CashMovement::query()->where('origin_type', $model)->whereIn('origin_id', $payments->modelKeys())->get()->keyBy('origin_id');
                    foreach ($payments as $payment) {
                        $movement = $movements->get($payment->id);
                        if ($payment->financial_account_id && ! $movement) {
                            $report('pagamento_sem_movimento', $payment, 'Pagamento possui conta financeira, mas não possui movimento.');
                        } elseif ($movement && ((int) $payment->company_id !== (int) $movement->company_id
                            || (int) $payment->financial_account_id !== (int) $movement->financial_account_id
                            || $this->cents($payment->amount) !== $this->cents($movement->amount)
                            || ($movement->reversed_at !== null && (float) $payment->returned_amount <= 0))) {
                            $report('pagamento_movimento_divergente', $payment, 'Empresa, conta, valor ou estado de estorno incompatível.');
                        }
                    }
                });

            CashMovement::query()->where('origin_type', $model)
                ->when($companyId, fn ($query) => $query->where('company_id', (int) $companyId))
                ->whereNotNull('origin_id')->whereNotIn('origin_id', $model::query()->select('id'))
                ->whereNull('reversed_at')
                ->chunkById(200, function ($movements) use ($report): void {
                    foreach ($movements as $movement) {
                        $report('movimento_sem_origem', $movement, 'Pagamento de origem não existe e o movimento não foi estornado.');
                    }
                });
        }

        foreach ([AccountPayableInstallment::class => 'accountPayable', AccountReceivableInstallment::class => 'accountReceivable'] as $model => $relation) {
            $model::query()->with(['payments', $relation])
                ->when($companyId, fn ($query) => $query->where('company_id', (int) $companyId))
                ->chunkById(200, function ($installments) use ($relation, $report): void {
                    foreach ($installments as $installment) {
                        $paid = 0;
                        $due = $this->cents($installment->original_amount);
                        foreach ($installment->payments as $payment) {
                            $paid += $this->cents($payment->amount) - $this->cents($payment->returned_amount);
                            $due += $this->cents($payment->interest_amount) + $this->cents($payment->fine_amount) - $this->cents($payment->discount_amount);
                        }
                        $storedPaid = $relation === 'accountPayable' ? $installment->paid_amount : $installment->received_amount;
                        if ($paid > $due || $this->cents($installment->due_amount) !== $due
                            || $this->cents($storedPaid) !== $paid || $this->cents($installment->balance_amount) !== max($due - $paid, 0)) {
                            $report('totais_da_parcela_divergentes', $installment, 'Principal, encargos, baixas e saldo não fecham.');
                        }
                        $account = $installment->{$relation};
                        if (! $account || (int) $account->company_id !== (int) $installment->company_id) {
                            $report('empresa_divergente', $installment, 'Parcela sem cabeçalho válido da mesma empresa.');
                        } elseif ($account->status->value === 'cancelled' && $installment->status->value !== 'cancelled' && $installment->balance_amount > 0) {
                            $report('parcela_aberta_em_conta_cancelada', $installment, 'Cabeçalho cancelado com parcela em aberto.');
                        }
                    }
                });
        }

        $this->table(['Empresa', 'Registro', 'ID', 'Ocorrência', 'Detalhe'], $rows);
        $this->info("Ocorrências encontradas: {$total}. Exibidas: ".count($rows).'. Nenhum registro foi alterado.');

        return $total > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
