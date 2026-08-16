<?php

namespace App\Services\Financial\BankStatement;

use App\Models\AccountPayableInstallment;
use App\Models\AccountPayableInstallmentPayment;
use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Audit\AuditRecorder;
use App\Services\Financial\CashMovementService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveBankStatementLineService
{
    use HandlesServiceResponse;

    public function __construct(
        private readonly AccountPayableService $payableService = new AccountPayableService,
        private readonly AccountReceivableService $receivableService = new AccountReceivableService,
        private readonly CashMovementService $cashMovementService = new CashMovementService,
        private readonly SuggestBankStatementMatchesService $suggestService = new SuggestBankStatementMatchesService,
        private readonly BankStatementMovementEligibilityService $movementEligibility = new BankStatementMovementEligibilityService,
    ) {}

    public function reconcileWithCashMovement(BankStatementLine $line, int $cashMovementId, ?int $userId = null, array $decision = []): ?BankStatementLine
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($line, $cashMovementId, $userId, $decision) {
                $audit = app(AuditRecorder::class);
                $line = BankStatementLine::query()->lockForUpdate()->findOrFail($line->id);
                $this->assertLineCanBeResolved($line);

                $movement = CashMovement::query()
                    ->lockForUpdate()
                    ->find($cashMovementId);

                if (! $movement) {
                    throw ValidationException::withMessages([
                        'cash_movement_id' => ['Movimento financeiro não encontrado.'],
                    ]);
                }

                return $this->reconcileLockedLineWithMovement($line, $movement, $userId, $decision, $audit);
            });
        } catch (ValidationException $e) {
            $this->setError('Falha ao conciliar linha do extrato.', $e->errors(), 422);

            return null;
        } catch (QueryException $e) {
            return $this->handleReconciliationQueryException($e, 'Erro ao conciliar linha do extrato.');
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
            return DB::transaction(function () use ($line, $installmentId, $payload, $userId) {
                $audit = app(AuditRecorder::class);
                $line = BankStatementLine::query()->lockForUpdate()->findOrFail($line->id);
                $this->assertLineCanBeResolved($line);

                if (! $line->isOutflow()) {
                    throw ValidationException::withMessages([
                        'installment_id' => ['Somente linhas de saida podem baixar contas a pagar.'],
                    ]);
                }

                $installment = AccountPayableInstallment::query()
                    ->where('company_id', $line->company_id)
                    ->lockForUpdate()
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
                        'user_id' => $userId,
                    ],
                );

                if ($this->payableService->hasError() || $payment === null) {
                    $this->throwNestedServiceFailure($this->payableService->getMessageUser());
                }

                $movement = CashMovement::query()
                    ->where('origin_type', $payment::class)
                    ->where('origin_id', $payment->id)
                    ->lockForUpdate()
                    ->first();

                if (! $movement) {
                    throw ValidationException::withMessages([
                        'cash_movement_id' => ['Nao foi possivel localizar o movimento financeiro da baixa da parcela.'],
                    ]);
                }

                return $this->reconcileLockedLineWithMovement($line, $movement, $userId, [
                    'type' => 'account_payable_installment',
                    'installment_id' => $installment->id,
                    'payment_id' => $payment->id,
                    'interest_amount' => (float) ($payload['interest_amount'] ?? 0),
                    'fine_amount' => (float) ($payload['fine_amount'] ?? 0),
                    'discount_amount' => (float) ($payload['discount_amount'] ?? 0),
                ], $audit);
            });
        } catch (ValidationException $e) {
            $this->setError('Falha ao baixar parcela a pagar.', $e->errors(), 422);

            return null;
        } catch (QueryException $e) {
            return $this->handleReconciliationQueryException($e, 'Erro ao baixar parcela a pagar.');
        } catch (\Throwable $e) {
            $this->setError('Erro ao baixar parcela a pagar.', ['exception' => [$e->getMessage()]]);

            return null;
        }
    }

    public function reconcileWithReceivableInstallment(BankStatementLine $line, int $installmentId, array $payload = [], ?int $userId = null): ?BankStatementLine
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($line, $installmentId, $payload, $userId) {
                $audit = app(AuditRecorder::class);
                $line = BankStatementLine::query()->lockForUpdate()->findOrFail($line->id);
                $this->assertLineCanBeResolved($line);

                if (! $line->isInflow()) {
                    throw ValidationException::withMessages([
                        'installment_id' => ['Somente linhas de entrada podem baixar contas a receber.'],
                    ]);
                }

                $installment = AccountReceivableInstallment::query()
                    ->where('company_id', $line->company_id)
                    ->lockForUpdate()
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
                        'user_id' => $userId,
                    ],
                );

                if ($this->receivableService->hasError() || $payment === null) {
                    $this->throwNestedServiceFailure($this->receivableService->getMessageUser());
                }

                $movement = CashMovement::query()
                    ->where('origin_type', $payment::class)
                    ->where('origin_id', $payment->id)
                    ->lockForUpdate()
                    ->first();

                if (! $movement) {
                    throw ValidationException::withMessages([
                        'cash_movement_id' => ['Nao foi possivel localizar o movimento financeiro da baixa da parcela.'],
                    ]);
                }

                return $this->reconcileLockedLineWithMovement($line, $movement, $userId, [
                    'type' => 'account_receivable_installment',
                    'installment_id' => $installment->id,
                    'payment_id' => $payment->id,
                    'interest_amount' => (float) ($payload['interest_amount'] ?? 0),
                    'fine_amount' => (float) ($payload['fine_amount'] ?? 0),
                    'discount_amount' => (float) ($payload['discount_amount'] ?? 0),
                ], $audit);
            });
        } catch (ValidationException $e) {
            $this->setError('Falha ao baixar parcela a receber.', $e->errors(), 422);

            return null;
        } catch (QueryException $e) {
            return $this->handleReconciliationQueryException($e, 'Erro ao baixar parcela a receber.');
        } catch (\Throwable $e) {
            $this->setError('Erro ao baixar parcela a receber.', ['exception' => [$e->getMessage()]]);

            return null;
        }
    }

    public function createManualMovement(BankStatementLine $line, array $payload, ?int $userId = null): ?BankStatementLine
    {
        $this->resetResponse();
        try {
            return DB::transaction(function () use ($line, $payload, $userId) {
                $audit = app(AuditRecorder::class);
                $line = BankStatementLine::query()->lockForUpdate()->findOrFail($line->id);
                $this->assertLineCanBeResolved($line);

                $movement = $this->cashMovementService->createManual([
                    'company_id' => $line->company_id,
                    'financial_account_id' => $line->financial_account_id,
                    'financial_category_id' => (int) ($payload['financial_category_id'] ?? 0),
                    'direction' => $line->direction()?->value,
                    'transaction_date' => $payload['transaction_date'] ?? $line->transaction_date?->toDateString(),
                    'amount' => (float) $line->amount,
                    'description' => $payload['description'] ?? $line->description,
                    'counterparty_partner_id' => $payload['counterparty_partner_id'] ?? null,
                    'manual_counterparty_name' => $payload['manual_counterparty_name'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                ], $userId);

                if ($this->cashMovementService->hasError() || $movement === null) {
                    $this->throwNestedServiceFailure($this->cashMovementService->getMessageUser());
                }

                $movement = CashMovement::query()->lockForUpdate()->findOrFail($movement->id);
                $resolved = $this->reconcileLockedLineWithMovement($line, $movement, $userId, [
                    'type' => 'manual',
                    'created_cash_movement_id' => $movement->id,
                ], $audit);

                $import = $line->import()->first();

                if ($import) {
                    $audit->recordModelEvent(
                        $import,
                        'bank_statement_import.manual_movement_created',
                        "Movimento manual criado para a linha #{$line->id}",
                        null,
                        $audit->snapshot($import),
                        $userId,
                        null,
                        [
                            'bank_statement_line_id' => $line->id,
                            'cash_movement_id' => $movement->id,
                        ],
                    );
                }

                return $resolved;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha ao criar movimento manual.', $e->errors(), 422);

            return null;
        } catch (QueryException $e) {
            return $this->handleReconciliationQueryException($e, 'Erro ao criar movimento manual.');
        } catch (\Throwable $e) {
            $this->setError('Erro ao criar movimento manual.', ['exception' => [$e->getMessage()]]);

            return null;
        }
    }

    public function ignore(BankStatementLine $line, ?int $userId = null, ?string $reason = null): ?BankStatementLine
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($line, $userId, $reason) {
                $audit = app(AuditRecorder::class);
                $line = BankStatementLine::query()->lockForUpdate()->findOrFail($line->id);
                $this->assertLineCanBeResolved($line);
                $line->update([
                    'reconciliation_status' => 'ignored',
                    'metadata' => $this->mergeDecisionMetadata($line, [
                        'type' => 'ignored',
                        'reason' => $reason,
                        'resolved_by' => $userId,
                        'resolved_at' => now()->toDateTimeString(),
                    ]),
                ]);

                $import = $line->import()->first();

                if ($import) {
                    $audit->recordModelEvent(
                        $import,
                        'bank_statement_import.line_ignored',
                        "Linha #{$line->id} ignorada",
                        null,
                        $audit->snapshot($import),
                        $userId,
                        null,
                        [
                            'bank_statement_line_id' => $line->id,
                            'reason' => $reason,
                        ],
                    );
                }

                $this->setSuccess('Linha marcada como ignorada.');

                return $line->fresh();
            });
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

    public function reopenIgnored(BankStatementLine $line, int $userId, string $reason): ?BankStatementLine
    {
        $this->resetResponse();

        try {
            if (blank($reason)) {
                throw ValidationException::withMessages([
                    'reason' => ['Informe o motivo para reabrir a linha ignorada.'],
                ]);
            }

            return DB::transaction(function () use ($line, $userId, $reason) {
                $audit = app(AuditRecorder::class);
                $line = BankStatementLine::query()->lockForUpdate()->findOrFail($line->id);

                if ($line->reconciliation_status?->value !== 'ignored') {
                    throw ValidationException::withMessages([
                        'line' => ['Somente linhas ignoradas podem ser reabertas.'],
                    ]);
                }

                $line->update([
                    'reconciliation_status' => 'pending',
                    'metadata' => $this->mergeDecisionMetadata($line, [
                        'type' => 'reopened',
                        'reason' => $reason,
                        'resolved_by' => $userId,
                        'resolved_at' => now()->toDateTimeString(),
                    ]),
                ]);

                $import = $line->import()->first();

                if ($import) {
                    $audit->recordModelEvent(
                        $import,
                        'bank_statement_import.line_reopened',
                        "Linha #{$line->id} reaberta",
                        null,
                        $audit->snapshot($import),
                        $userId,
                        null,
                        [
                            'bank_statement_line_id' => $line->id,
                            'reason' => $reason,
                        ],
                    );
                }

                $this->setSuccess('Linha ignorada reaberta para conciliação.');

                return $line->fresh();
            });
        } catch (ValidationException $e) {
            $this->setError('Falha ao reabrir linha do extrato.', $e->errors(), 422);

            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro ao reabrir linha do extrato.', [
                'exception' => [$e->getMessage()],
            ]);

            return null;
        }
    }

    public function reverseReconciliation(BankStatementLine $line, ?int $userId, string $reason): ?BankStatementLine
    {
        $this->resetResponse();

        try {
            if (blank($reason)) {
                throw ValidationException::withMessages([
                    'reason' => ['Informe o motivo para desfazer a conciliação.'],
                ]);
            }

            return DB::transaction(function () use ($line, $userId, $reason) {
                $audit = app(AuditRecorder::class);
                $line = BankStatementLine::query()->lockForUpdate()->findOrFail($line->id);

                if ($line->reconciliation_status?->value !== 'reconciled') {
                    throw ValidationException::withMessages([
                        'line' => ['Somente linhas conciliadas podem ter a conciliação desfeita.'],
                    ]);
                }

                $decision = data_get($line->metadata, 'decision', []);
                $movement = $line->cash_movement_id
                    ? CashMovement::query()->lockForUpdate()->find($line->cash_movement_id)
                    : null;
                $type = $decision['type'] ?? 'cash_movement';
                $nextStatus = 'reversed';
                $movementId = $movement?->id;

                if ($type === 'cash_movement') {
                    $nextStatus = 'pending';
                } elseif ($type === 'account_payable_installment') {
                    $payment = AccountPayableInstallmentPayment::query()->lockForUpdate()->find($decision['payment_id'] ?? null);

                    if (! $payment || ! $this->payableService->deleteInstallmentPayment($payment)) {
                        $this->throwNestedServiceFailure($this->payableService->getMessageUser());
                    }
                } elseif ($type === 'account_receivable_installment') {
                    $payment = AccountReceivableInstallmentPayment::query()->lockForUpdate()->find($decision['payment_id'] ?? null);

                    if (! $payment || ! $this->receivableService->deleteInstallmentPayment($payment)) {
                        $this->throwNestedServiceFailure($this->receivableService->getMessageUser());
                    }
                } elseif ($type === 'manual') {
                    if (! $movement) {
                        throw ValidationException::withMessages([
                            'cash_movement_id' => ['Movimento manual da conciliação não encontrado.'],
                        ]);
                    }

                    $line->update(['cash_movement_id' => null]);

                    if (! $this->cashMovementService->deleteSafely($movement, $userId)) {
                        $this->throwNestedServiceFailure($this->cashMovementService->getMessageUser());
                    }

                    $movementId = null;
                }

                $line->update([
                    'cash_movement_id' => $nextStatus === 'pending' ? null : $movementId,
                    'reconciliation_status' => $nextStatus,
                    'reconciled_at' => null,
                    'metadata' => $this->mergeDecisionMetadata($line, [
                        'type' => 'reconciliation_reversed',
                        'reason' => $reason,
                        'previous_decision' => $decision,
                        'resolved_by' => $userId,
                        'resolved_at' => now()->toDateTimeString(),
                    ]),
                ]);

                $import = $line->import()->first();

                if ($import) {
                    $audit->recordModelEvent(
                        $import,
                        'bank_statement_import.reconciliation_reversed',
                        "Conciliação da linha #{$line->id} desfeita",
                        null,
                        $audit->snapshot($import),
                        $userId,
                        null,
                        ['bank_statement_line_id' => $line->id, 'reason' => $reason, 'type' => $type],
                    );
                }

                $this->setSuccess('Conciliação desfeita com sucesso.');

                return $line->fresh();
            });
        } catch (ValidationException $e) {
            $this->setError('Falha ao desfazer conciliação.', $e->errors(), 422);

            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro ao desfazer conciliação.', ['exception' => [$e->getMessage()]]);

            return null;
        }
    }

    public function resolveReview(BankStatementLine $line, ?int $userId, string $decision, string $reason): ?BankStatementLine
    {
        $this->resetResponse();

        try {
            if (blank($reason) || ! in_array($decision, ['keep', 'reopen'], true)) {
                throw ValidationException::withMessages([
                    'review' => ['Informe uma decisão e sua justificativa para concluir a revisão.'],
                ]);
            }

            return DB::transaction(function () use ($line, $userId, $decision, $reason) {
                $audit = app(AuditRecorder::class);
                $line = BankStatementLine::query()->lockForUpdate()->findOrFail($line->id);

                if ($line->reconciliation_status?->value !== 'needs_review') {
                    throw ValidationException::withMessages([
                        'line' => ['Somente linhas em revisão podem receber esta decisão.'],
                    ]);
                }

                if ($decision === 'keep' && ! $line->cash_movement_id) {
                    throw ValidationException::withMessages([
                        'review' => ['Não existe efeito financeiro para manter nesta linha. Reabra-a como pendente.'],
                    ]);
                }

                if ($decision === 'reopen' && $line->cash_movement_id) {
                    throw ValidationException::withMessages([
                        'review' => ['Desfaça a conciliação para reabrir uma linha com efeito financeiro.'],
                    ]);
                }

                $nextStatus = $decision === 'keep' ? 'reconciled' : 'pending';
                $line->update([
                    'reconciliation_status' => $nextStatus,
                    'needs_review_at' => null,
                    'review_reason' => null,
                    'metadata' => $this->mergeDecisionMetadata($line, [
                        'type' => 'review_resolved',
                        'decision' => $decision,
                        'reason' => $reason,
                        'resolved_by' => $userId,
                        'resolved_at' => now()->toDateTimeString(),
                    ]),
                ]);

                $import = $line->import()->first();

                if ($import) {
                    $audit->recordModelEvent(
                        $import,
                        'bank_statement_import.review_resolved',
                        "Revisão da linha #{$line->id} concluída",
                        null,
                        $audit->snapshot($import),
                        $userId,
                        null,
                        ['bank_statement_line_id' => $line->id, 'decision' => $decision, 'reason' => $reason],
                    );
                }

                $this->setSuccess('Revisão da linha concluída.');

                return $line->fresh();
            });
        } catch (ValidationException $e) {
            $this->setError('Falha ao concluir revisão.', $e->errors(), 422);

            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro ao concluir revisão.', ['exception' => [$e->getMessage()]]);

            return null;
        }
    }

    /**
     * The caller must hold locks for both records inside the orchestration transaction.
     *
     * @param  array<string, mixed>  $decision
     */
    private function reconcileLockedLineWithMovement(
        BankStatementLine $line,
        CashMovement $movement,
        ?int $userId,
        array $decision,
        AuditRecorder $audit,
    ): BankStatementLine {
        $exceptions = $this->movementEligibility->assertEligible(
            $line,
            $movement,
            $decision['exception_reason'] ?? null,
        );

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
                'eligibility_exceptions' => $exceptions,
                ...$decision,
            ]),
        ]);

        $import = $line->import()->first();

        if ($import) {
            $audit->recordModelEvent(
                $import,
                'bank_statement_import.movement_reconciled',
                "Linha #{$line->id} conciliada com movimento financeiro",
                null,
                $audit->snapshot($import),
                $userId,
                null,
                [
                    'bank_statement_line_id' => $line->id,
                    'cash_movement_id' => $movement->id,
                    'decision' => $decision,
                ],
            );
        }

        $this->setSuccess('Linha do extrato conciliada com sucesso.');

        return $line->fresh();
    }

    private function throwNestedServiceFailure(?string $message): never
    {
        throw ValidationException::withMessages([
            'operation' => [$message ?: 'Não foi possível concluir a operação financeira.'],
        ]);
    }

    private function handleReconciliationQueryException(QueryException $exception, string $fallbackMessage): ?BankStatementLine
    {
        if ((string) $exception->getCode() === '23000' && str_contains($exception->getMessage(), 'cash_movement_id')) {
            $this->setError('Falha ao conciliar linha do extrato.', [
                'cash_movement_id' => ['Este movimento ja esta conciliado com outra linha de extrato.'],
            ], 422);

            return null;
        }

        $this->setError($fallbackMessage, ['exception' => [$exception->getMessage()]]);

        return null;
    }

    private function assertLineCanBeResolved(BankStatementLine $line): void
    {
        if (! $line->reconciliation_status?->canResolve()) {
            throw ValidationException::withMessages([
                'line' => ['Somente linhas pendentes podem receber uma nova conciliação.'],
            ]);
        }
    }

    private function mergeDecisionMetadata(BankStatementLine $line, array $decision): array
    {
        $metadata = $line->metadata ?? [];

        if (is_array($metadata['decision'] ?? null)) {
            $metadata['decision_history'] ??= [];
            $metadata['decision_history'][] = $metadata['decision'];
        }

        return array_merge($metadata, ['decision' => $decision]);
    }
}
