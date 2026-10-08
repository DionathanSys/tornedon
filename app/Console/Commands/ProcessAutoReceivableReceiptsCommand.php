<?php

namespace App\Console\Commands;

use App\Enum\AccountReceivable\Status;
use App\Models\AccountReceivableInstallment;
use App\Models\FinancialAccount;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Support\Financial\InstallmentDescription;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAutoReceivableReceiptsCommand extends Command
{
    protected $signature = 'account-receivables:process-auto-receipts {--date=}';

    protected $description = 'Registra automaticamente o saldo das parcelas a receber no vencimento.';

    public function handle(): int
    {
        $targetDate = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))->toDateString()
            : now()->toDateString();
        $processed = 0;
        $failed = 0;

        AccountReceivableInstallment::query()
            ->whereDate('due_date', $targetDate)
            ->where('balance_amount', '>', 0)
            ->where('status', '!=', Status::CANCELLED->value)
            ->whereHas('accountReceivable', fn ($query) => $query
                ->where('auto_register_receipt_on_due_date', true)
                ->where('status', '!=', Status::CANCELLED->value)
                ->whereNotNull('auto_receipt_financial_account_id'))
            ->chunkById(100, function ($installments) use ($targetDate, &$processed, &$failed): void {
                foreach ($installments as $candidate) {
                    try {
                        $received = DB::transaction(function () use ($candidate, $targetDate): bool {
                            // Serialize manual receipts and concurrent scheduler runs on this installment.
                            $installment = AccountReceivableInstallment::query()->lockForUpdate()->findOrFail($candidate->id);
                            $receivable = $installment->accountReceivable;

                            if ((float) $installment->balance_amount <= 0 || ! $receivable->auto_register_receipt_on_due_date
                                || $installment->status === Status::CANCELLED || $receivable->status === Status::CANCELLED) {
                                return false;
                            }

                            $account = FinancialAccount::query()->where('company_id', $receivable->company_id)
                                ->where('is_active', true)->find($receivable->auto_receipt_financial_account_id);
                            if (! $account) {
                                throw new \RuntimeException('Conta financeira da baixa automática inválida ou inativa.');
                            }

                            $service = app(AccountReceivableService::class);
                            $payment = $service->registerInstallmentPayment($installment,
                                (float) $installment->balance_amount, $targetDate, [
                                    'financial_account_id' => $account->id,
                                    'description' => InstallmentDescription::forReceivableInstallment($installment),
                                    'notes' => 'Recebimento registrado automaticamente no vencimento.',
                                ]);
                            if (! $payment) {
                                throw new \RuntimeException($service->getMessage());
                            }

                            return true;
                        });
                        $processed += (int) $received;
                    } catch (Throwable $exception) {
                        $failed++;
                        Log::error('Falha na baixa automática de recebível', [
                            'installment_id' => $candidate->id,
                            'target_date' => $targetDate,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Recebimentos gerados: {$processed}. Falhas: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
