<?php

namespace App\Services\CompanyCard;

use App\Enum\CompanyCard\StatementStatus;
use App\Models\AccountPayableInstallment;
use App\Models\CompanyCardStatement;
use App\Models\CompanyCardStatementPayment;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\Audit\AuditRecorder;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyCardStatementPaymentService
{
    use HandlesServiceResponse;

    public function __construct(
        private readonly AccountPayableService $accountPayableService = new AccountPayableService(),
        private readonly CompanyCardStatementService $statementService = new CompanyCardStatementService(),
    ) {}

    /**
     * @param array<string, mixed> $extra
     */
    public function registerPayment(
        CompanyCardStatement $statement,
        float $amount,
        string $paymentDate,
        array $extra = []
    ): ?CompanyCardStatementPayment {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($statement, $amount, $paymentDate, $extra) {
                $statement->loadMissing('companyCreditCard', 'accountPayable.installments');

                if (! $statement->account_payable_id || ! $statement->accountPayable) {
                    throw ValidationException::withMessages([
                        'company_card_statement_id' => ['A fatura precisa estar fechada com conta a pagar vinculada para receber pagamento.'],
                    ]);
                }

                $installment = $statement->accountPayable->installments()
                    ->orderBy('sequence_number')
                    ->first();

                if (! $installment instanceof AccountPayableInstallment) {
                    throw ValidationException::withMessages([
                        'account_payable_id' => ['Nenhuma parcela encontrada na conta a pagar vinculada.'],
                    ]);
                }

                $financialAccountId = $extra['financial_account_id']
                    ?? $statement->companyCreditCard->default_financial_account_id
                    ?? null;

                $payablePayment = $this->accountPayableService->registerInstallmentPayment(
                    $installment,
                    $amount,
                    $paymentDate,
                    [
                        'financial_account_id' => $financialAccountId,
                        'description' => $extra['description'] ?? 'Pagamento de fatura de cartão corporativo',
                        'notes' => $extra['notes'] ?? null,
                        'user_id' => $extra['user_id'] ?? auth()->id(),
                    ]
                );

                if ($this->accountPayableService->hasError() || ! $payablePayment) {
                    $this->setError(
                        $this->accountPayableService->getMessage(),
                        $this->accountPayableService->getErrors(),
                        422,
                        $this->accountPayableService->getErrorCode(),
                    );

                    return null;
                }

                $statementPayment = CompanyCardStatementPayment::query()->create([
                    'company_id' => $statement->company_id,
                    'company_card_statement_id' => $statement->id,
                    'account_payable_installment_payment_id' => $payablePayment->id,
                    'payment_date' => $paymentDate,
                    'amount' => $amount,
                    'financial_account_id' => $financialAccountId,
                    'notes' => $extra['notes'] ?? null,
                ]);

                $updatedStatement = $this->syncStatus($statement, isset($extra['user_id']) ? (int) $extra['user_id'] : null);
                $audit = app(AuditRecorder::class);
                $audit->recordModelEvent(
                    $updatedStatement,
                    'company_card_statement.payment_registered',
                    'Pagamento registrado para fatura de cartão corporativo',
                    null,
                    $audit->snapshot($updatedStatement),
                    $extra['user_id'] ?? auth()->id(),
                    null,
                    [
                        'statement_payment_id' => $statementPayment->id,
                        'payable_payment_id' => $payablePayment->id,
                        'amount' => (float) $amount,
                    ],
                );

                $this->setSuccess('Pagamento da fatura registrado com sucesso.');

                return $statementPayment;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados.', $e->errors(), 422);
            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro ao registrar pagamento da fatura de cartão.');
            return null;
        }
    }

    public function syncStatus(CompanyCardStatement $statement, ?int $userId = null): CompanyCardStatement
    {
        $before = app(AuditRecorder::class)->snapshot($statement->fresh());
        $updated = $this->statementService->recalculateTotals($statement->fresh());

        if ((float) $updated->balance_total <= 0 && (float) $updated->paid_total > 0) {
            $updated->update([
                'status' => StatementStatus::PAID->value,
                'paid_at' => now(),
            ]);
        }

        $updated = $updated->fresh();
        $audit = app(AuditRecorder::class);
        $audit->recordModelEvent(
            $updated,
            'company_card_statement.status_synced',
            'Status da fatura de cartao corporativo sincronizado',
            $before,
            $audit->snapshot($updated),
            $userId,
        );

        return $updated;
    }
}
