<?php

namespace App\Console\Commands;

use App\Models\AccountPayableInstallment;
use App\Services\AccountPayable\AccountPayableService;
use App\Support\Financial\InstallmentDescription;
use App\Support\Financial\InstallmentPaymentGuard;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessAutoPayablePaymentsCommand extends Command
{
    protected $signature = 'account-payables:process-auto-payments {--date=}';

    protected $description = 'Registra automaticamente pagamentos de parcelas a pagar com vencimento no dia.';

    public function handle(): int
    {
        $targetDate = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))->toDateString()
            : now()->toDateString();

        $service = app(AccountPayableService::class);
        $processed = 0;
        $skipped = 0;
        $failed = 0;

        AccountPayableInstallment::query()
            ->considered()
            ->with(['accountPayable.supplier'])
            ->whereDate('due_date', $targetDate)
            ->where('balance_amount', '>', 0)
            ->where('status', '!=', 'cancelled')
            ->whereHas('accountPayable', function ($query): void {
                $query
                    ->where('is_effective', true)
                    ->where('status', '!=', 'cancelled')
                    ->where('auto_register_payment_on_due_date', true)
                    ->whereNotNull('auto_payment_financial_account_id');
            })
            ->orderBy('id')
            ->chunkById(100, function ($installments) use ($service, $targetDate, &$processed, &$skipped, &$failed): void {
                foreach ($installments as $installment) {
                    try {
                        $payment = DB::transaction(function () use ($installment, $service, $targetDate) {
                            $installment = InstallmentPaymentGuard::lock($installment);
                            $account = $installment->accountPayable;
                            if ((float) $installment->balance_amount <= 0 || ! $account->is_effective
                                || ! $account->auto_register_payment_on_due_date
                                || $account->status->value === 'cancelled' || $installment->status->value === 'cancelled') {
                                return false;
                            }

                            $payment = $service->registerInstallmentPayment($installment, (float) $installment->balance_amount, $targetDate, [
                                'financial_account_id' => $account->auto_payment_financial_account_id,
                                'origin' => 'due_date',
                                'description' => InstallmentDescription::forPayableInstallment($installment),
                                'notes' => 'Pagamento registrado automaticamente no vencimento.',
                            ]);
                            if (! $payment) {
                                throw new \RuntimeException($service->getMessage());
                            }

                            return $payment;
                        });
                        if ($payment === false) {
                            $skipped++;

                            continue;
                        }
                    } catch (\Throwable $exception) {
                        $failed++;

                        Log::error('ProcessAutoPayablePaymentsCommand: falha ao registrar pagamento automatico', [
                            'installment_id' => $installment->id,
                            'account_payable_id' => $installment->account_payable_id,
                            'target_date' => $targetDate,
                            'errors' => $service->getErrors(),
                            'message' => $exception->getMessage(),
                        ]);

                        continue;
                    }

                    $processed++;
                }
            });

        $this->info(sprintf(
            'Processamento concluido. Pagamentos gerados: %d. Ignorados: %d. Falhas: %d.',
            $processed,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
