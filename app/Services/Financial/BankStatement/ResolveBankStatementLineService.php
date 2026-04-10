<?php

namespace App\Services\Financial\BankStatement;

use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Financial\CashMovementService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveBankStatementLineService
{
    use HandlesServiceResponse;

    public function __construct(
        private readonly AccountPayableService $payableService = new AccountPayableService(),
        private readonly AccountReceivableService $receivableService = new AccountReceivableService(),
        private readonly CashMovementService $cashMovementService = new CashMovementService(),
        private readonly SuggestBankStatementMatchesService $suggestService = new SuggestBankStatementMatchesService(),
    ) {}

    public function reconcileWithCashMovement(BankStatementLine $line, int $cashMovementId, ?int $userId = null, array $decision = []): ?BankStatementLine
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($line, $cashMovementId, $userId, $decision) {
                $line = $line->fresh();
                $this->assertLineCanBeResolved($line);

                $movement = CashMovement::query()
                    ->where('company_id', $line->company_id)
                    ->where('financial_account_id', $line->financial_account_id)
                    ->find($cashMovementId);

                if (! $movement) {
                    throw ValidationException::withMessages([
                        'cash_movement_id' => ['Movimento financeiro nao encontrado para a conta informada.'],
                    ]);
                }

                $alreadyLinked = BankStatementLine::query()
                    ->where('cash_movement_id', $movement->id)
                    ->where('id', '!=', $line->id)
                    ->exists();

                if ($alreadyLinked) {
                    throw ValidationException::withMessages([
                        'cash_movement_id' => ['Este movimento ja esta conciliado com outra linha de extrato.'],
                    ]);
                }

                $line->update([
                    'cash_movement_id' => $movement->id,
                    'reconciliation_status' => 'reconciled',
                    'reconciled_at' => now(),
                    'metadata' => $this->mergeDecisionMetadata($line, [
                        'type' => $decision['type'] ?? 'cash_movement',
                        'cash_movement_id' => $movement->id,
                        'resolved_by' => $userId,
                        'resolved_at' => now()->toDateTimeString(),
                        ...$decision,
                    ]),
                ]);

                $this->setSuccess('Linha do extrato conciliada com sucesso.');

                return $line->fresh();
            });
        } catch (ValidationException $e) {
            $this->setError('Falha ao conciliar linha do extrato.', $e->errors(), 422);

            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro ao conciliar linha do extrato.', [
                'exception' => [$e->getMessage()],
            ]);

            return null;
        }
    }

    public function reconcileWithPayableInstallment(BankStatementLine $line, int $installmentId, array $payload = [], ?int $userId = null): ?BankStatementLine
    {
        $this->resetResponse();

        try {
            if (! $line->isOutflow()) {
                throw ValidationException::withMessages([
                    'installment_id' => ['Somente linhas de saida podem baixar contas a pagar.'],
                ]);
            }

            $installment = AccountPayableInstallment::query()
                ->where('company_id', $line->company_id)
                ->find($installmentId);

            if (! $installment) {
                throw ValidationException::withMessages([
                    'installment_id' => ['Parcela de conta a pagar nao encontrada.'],
                ]);
            }

            $payment = $this->payableService->registerInstallmentPayment(
                $installment,
                (float) $line->amount,
                (string) ($payload['payment_date'] ?? $line->transaction_date?->toDateString()),
                [
                    'interest_amount' => (float) ($payload['interest_amount'] ?? 0),
                    'fine_amount' => (float) ($payload['fine_amount'] ?? 0),
                    'discount_amount' => (float) ($payload['discount_amount'] ?? 0),
                    'financial_account_id' => $line->financial_account_id,
                    'notes' => $payload['notes'] ?? $line->description,
                ],
            );

            if ($this->payableService->hasError() || $payment === null) {
                $this->setError(
                    $this->payableService->getMessage(),
                    $this->payableService->getErrors(),
                    422,
                    $this->payableService->getErrorCode()
                );

                return null;
            }

            $movement = CashMovement::query()
                ->where('origin_type', $payment::class)
                ->where('origin_id', $payment->id)
                ->first();

            if (! $movement) {
                throw ValidationException::withMessages([
                    'cash_movement_id' => ['Nao foi possivel localizar o movimento financeiro da baixa da parcela.'],
                ]);
            }

            return $this->reconcileWithCashMovement($line, $movement->id, $userId, [
                'type' => 'account_payable_installment',
                'installment_id' => $installment->id,
                'payment_id' => $payment->id,
                'interest_amount' => (float) ($payload['interest_amount'] ?? 0),
                'fine_amount' => (float) ($payload['fine_amount'] ?? 0),
                'discount_amount' => (float) ($payload['discount_amount'] ?? 0),
            ]);
        } catch (ValidationException $e) {
            $this->setError('Falha ao baixar parcela a pagar.', $e->errors(), 422);

            return null;
        }
    }

    public function reconcileWithReceivableInstallment(BankStatementLine $line, int $installmentId, array $payload = [], ?int $userId = null): ?BankStatementLine
    {
        $this->resetResponse();

        try {
            if (! $line->isInflow()) {
                throw ValidationException::withMessages([
                    'installment_id' => ['Somente linhas de entrada podem baixar contas a receber.'],
                ]);
            }

            $installment = AccountReceivableInstallment::query()
                ->where('company_id', $line->company_id)
                ->find($installmentId);

            if (! $installment) {
                throw ValidationException::withMessages([
                    'installment_id' => ['Parcela de conta a receber nao encontrada.'],
                ]);
            }

            $payment = $this->receivableService->registerInstallmentPayment(
                $installment,
                (float) $line->amount,
                (string) ($payload['payment_date'] ?? $line->transaction_date?->toDateString()),
                [
                    'interest_amount' => (float) ($payload['interest_amount'] ?? 0),
                    'fine_amount' => (float) ($payload['fine_amount'] ?? 0),
                    'discount_amount' => (float) ($payload['discount_amount'] ?? 0),
                    'financial_account_id' => $line->financial_account_id,
                    'notes' => $payload['notes'] ?? $line->description,
                ],
            );

            if ($this->receivableService->hasError() || $payment === null) {
                $this->setError(
                    $this->receivableService->getMessage(),
                    $this->receivableService->getErrors(),
                    422,
                    $this->receivableService->getErrorCode()
                );

                return null;
            }

            $movement = CashMovement::query()
                ->where('origin_type', $payment::class)
                ->where('origin_id', $payment->id)
                ->first();

            if (! $movement) {
                throw ValidationException::withMessages([
                    'cash_movement_id' => ['Nao foi possivel localizar o movimento financeiro da baixa da parcela.'],
                ]);
            }

            return $this->reconcileWithCashMovement($line, $movement->id, $userId, [
                'type' => 'account_receivable_installment',
                'installment_id' => $installment->id,
                'payment_id' => $payment->id,
                'interest_amount' => (float) ($payload['interest_amount'] ?? 0),
                'fine_amount' => (float) ($payload['fine_amount'] ?? 0),
                'discount_amount' => (float) ($payload['discount_amount'] ?? 0),
            ]);
        } catch (ValidationException $e) {
            $this->setError('Falha ao baixar parcela a receber.', $e->errors(), 422);

            return null;
        }
    }

    public function createManualMovement(BankStatementLine $line, array $payload, ?int $userId = null): ?BankStatementLine
    {
        $this->resetResponse();

        $movement = $this->cashMovementService->createManual([
            'company_id' => $line->company_id,
            'financial_account_id' => $line->financial_account_id,
            'financial_category_id' => (int) ($payload['financial_category_id'] ?? 0),
            'direction' => $line->direction()?->value,
            'transaction_date' => $payload['transaction_date'] ?? $line->transaction_date?->toDateString(),
            'amount' => (float) $line->amount,
            'description' => $payload['description'] ?? $line->description,
            'notes' => $payload['notes'] ?? null,
        ], $userId);

        if ($this->cashMovementService->hasError() || $movement === null) {
            $this->setError(
                $this->cashMovementService->getMessage(),
                $this->cashMovementService->getErrors(),
                422,
                $this->cashMovementService->getErrorCode()
            );

            return null;
        }

        return $this->reconcileWithCashMovement($line, $movement->id, $userId, [
            'type' => 'manual',
            'created_cash_movement_id' => $movement->id,
        ]);
    }

    public function ignore(BankStatementLine $line, ?int $userId = null, ?string $reason = null): ?BankStatementLine
    {
        $this->resetResponse();

        try {
            $line->update([
                'reconciliation_status' => 'ignored',
                'metadata' => $this->mergeDecisionMetadata($line, [
                    'type' => 'ignored',
                    'reason' => $reason,
                    'resolved_by' => $userId,
                    'resolved_at' => now()->toDateTimeString(),
                ]),
            ]);

            $this->setSuccess('Linha marcada como ignorada.');

            return $line->fresh();
        } catch (\Throwable $e) {
            $this->setError('Erro ao ignorar linha do extrato.', [
                'exception' => [$e->getMessage()],
            ]);

            return null;
        }
    }

    public function refreshSuggestions(BankStatementLine $line): array
    {
        return $this->suggestService->suggestForLine($line->fresh());
    }

    private function assertLineCanBeResolved(BankStatementLine $line): void
    {
        if ($line->reconciliation_status?->value === 'reconciled') {
            throw ValidationException::withMessages([
                'line' => ['Esta linha de extrato ja foi conciliada.'],
            ]);
        }
    }

    private function mergeDecisionMetadata(BankStatementLine $line, array $decision): array
    {
        return array_merge($line->metadata ?? [], [
            'decision' => $decision,
        ]);
    }
}
