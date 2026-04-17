<?php

namespace App\Console\Commands;

use App\Models\AccountPayableInstallment;
use App\Services\AccountPayable\AccountPayableService;
use App\Support\Financial\InstallmentDescription;
use Carbon\Carbon;
use Illuminate\Console\Command;
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
            ->with(['accountPayable.supplier'])
            ->whereDate('due_date', $targetDate)
            ->where('balance_amount', '>', 0)
            ->whereHas('accountPayable', function ($query): void {
                $query
                    ->where('is_effective', true)
                    ->where('auto_register_payment_on_due_date', true)
                    ->whereNotNull('auto_payment_financial_account_id');
            })
            ->orderBy('id')
            ->chunkById(100, function ($installments) use ($service, $targetDate, &$processed, &$skipped, &$failed): void {
                foreach ($installments as $installment) {
                    $installment->loadMissing('accountPayable');

                    if ((float) $installment->balance_amount <= 0) {
                        $skipped++;
                        continue;
                    }

                    $payment = $service->registerInstallmentPayment(
                        installment: $installment,
                        amount: (float) $installment->balance_amount,
                        paymentDate: $targetDate,
                        extra: [
                            'financial_account_id' => $installment->accountPayable->auto_payment_financial_account_id,
                            'description' => InstallmentDescription::forPayableInstallment($installment),
                            'notes' => 'Pagamento registrado automaticamente no vencimento.',
                        ],
                    );

                    if ($payment === null) {
                        $failed++;

                        Log::error('ProcessAutoPayablePaymentsCommand: falha ao registrar pagamento automatico', [
                            'installment_id' => $installment->id,
                            'account_payable_id' => $installment->account_payable_id,
                            'target_date' => $targetDate,
                            'errors' => $service->getErrors(),
                            'message' => $service->getMessage(),
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
