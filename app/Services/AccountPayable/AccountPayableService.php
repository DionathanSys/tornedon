<?php

namespace App\Services\AccountPayable;

use App\Enum\AccountPayable\Status;
use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\AccountPayableInstallmentPayment;
use App\Services\AccountPayable\Actions\CreateAccountPayableAction;
use App\Services\AccountPayable\Actions\DeleteAccountPayableAction;
use App\Services\AccountPayable\Actions\Installment\CreateAccountPayableInstallmentAction;
use App\Services\AccountPayable\Actions\Installment\DeleteAccountPayableInstallmentAction;
use App\Services\AccountPayable\Actions\Installment\RegisterAccountPayableInstallmentPaymentAction;
use App\Services\AccountPayable\Actions\Installment\SyncAccountPayableStatusFromInstallmentsAction;
use App\Services\AccountPayable\Actions\Installment\UpdateAccountPayableInstallmentAction;
use App\Services\AccountPayable\Actions\UpdateAccountPayableAction;
use App\Services\Audit\AuditRecorder;
use App\Services\AccountPayable\Validators\AccountPayableInstallmentValidator;
use App\Services\AccountPayable\Validators\AccountPayableValidator;
use App\Services\Financial\CashMovementService;
use App\Services\Financial\FinancialClassificationService;
use App\Support\Financial\InstallmentDescription;
use App\Support\Financial\InstallmentSchedule;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AccountPayableService
{
    use HandlesServiceResponse;

    public function __construct(
        private readonly FinancialClassificationService $classificationService = new FinancialClassificationService(),
        private readonly CashMovementService $cashMovementService = new CashMovementService(),
    ) {}

    public function create(array $data, int $createdBy): ?AccountPayable
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $audit = app(AuditRecorder::class);
                AccountPayableValidator::validateCreate([
                    ...$data,
                    'sequence_number' => $data['sequence_number'] ?? '01',
                    'status' => $data['status'] ?? Status::PENDING->value,
                ]);

                $installmentCount = max(1, (int) ($data['installment_count'] ?? 1));
                $scheduleConfig = InstallmentSchedule::extractConfig($data);
                unset($data['installment_count']);

                $installments = $this->buildInstallmentsData($data, $installmentCount, $scheduleConfig);
                unset($data['amount_input_mode']);
                $action = new CreateAccountPayableAction($createdBy);
                $headerData = $this->buildHeaderData($data, $installments);
                $accountPayable = $action->execute($headerData);

                if ($action->hasError() || $accountPayable === null) {
                    throw ValidationException::withMessages(
                        $action->getErrors() ?: ['conta_a_pagar' => [$action->getMessage()]]
                    );
                }

                if (empty($installments)) {
                    throw new \RuntimeException('Nenhuma conta a pagar foi gerada.');
                }

                foreach ($installments as $installmentData) {
                    $createInstallmentAction = new CreateAccountPayableInstallmentAction();
                    $createdInstallment = $createInstallmentAction->execute(
                        $this->buildInstallmentRecordData($installmentData, $accountPayable)
                    );

                    if ($createInstallmentAction->hasError() || $createdInstallment === null) {
                        throw ValidationException::withMessages(
                            $createInstallmentAction->getErrors() ?: ['parcela' => [$createInstallmentAction->getMessage()]]
                        );
                    }
                }

                $syncAction = new SyncAccountPayableStatusFromInstallmentsAction($accountPayable);
                $syncedAccountPayable = $syncAction->execute();

                if ($syncAction->hasError() || $syncedAccountPayable === null) {
                    throw ValidationException::withMessages([
                        'conta_a_pagar' => [$syncAction->getMessage() ?: 'Falha ao sincronizar status da conta a pagar.'],
                    ]);
                }

                $syncedAccountPayable->refresh();
                $audit->recordModelEvent(
                    $syncedAccountPayable,
                    'account_payable.created',
                    'Conta a pagar criada',
                    null,
                    $audit->snapshot($syncedAccountPayable),
                    $createdBy,
                    null,
                    [
                        'installments_count' => $installmentCount,
                    ],
                );

                $this->setSuccess('Conta a pagar criada com sucesso');

                Log::info('Conta a pagar criada com sucesso via service', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'account_payable_id' => $syncedAccountPayable->id,
                    'installments' => $installmentCount,
                ]);

                return $syncedAccountPayable;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors(), 422);

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors' => $e->errors(),
                'data' => $data,
                'user_id' => $createdBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao criar conta a pagar');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
                'user_id' => $createdBy,
            ]);

            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildInstallmentsData(array $data, int $installmentCount, array $scheduleConfig = []): array
    {
        $amountMode = (string) ($data['amount_input_mode'] ?? 'total');
        $inputAmountInCents = (int) round(((float) ($data['due_amount'] ?? 0)) * 100);

        if ($installmentCount === 1) {
            $data['sequence_number'] = $this->formatSequenceNumber(1);
            return [$data];
        }

        $baseDate = Carbon::parse($data['due_date']);
        $installments = [];

        if ($amountMode === 'per_installment') {
            for ($index = 0; $index < $installmentCount; $index++) {
                $installments[] = [
                    ...$data,
                    'sequence_number' => $this->formatSequenceNumber($index + 1),
                    'due_date' => InstallmentSchedule::dueDate($baseDate, $index, $scheduleConfig)->toDateString(),
                    'due_amount' => $inputAmountInCents / 100,
                ];
            }

            return $installments;
        }

        $baseInstallment = intdiv($inputAmountInCents, $installmentCount);
        $remainder = $inputAmountInCents - ($baseInstallment * $installmentCount);

        for ($index = 0; $index < $installmentCount; $index++) {
            $amountInCents = $baseInstallment + ($index === $installmentCount - 1 ? $remainder : 0);
            $installments[] = [
                ...$data,
                'sequence_number' => $this->formatSequenceNumber($index + 1),
                'due_date' => InstallmentSchedule::dueDate($baseDate, $index, $scheduleConfig)->toDateString(),
                'due_amount' => $amountInCents / 100,
            ];
        }

        return $installments;
    }

    /**
     * @param  array<int, array<string, mixed>>  $installments
     * @return array<string, mixed>
     */
    private function buildHeaderData(array $data, array $installments): array
    {
        $totalAmount = round((float) array_sum(array_column($installments, 'due_amount')), 2);

        return [
            ...$data,
            'sequence_number' => '01',
            'status' => Status::PENDING->value,
            'due_date' => $installments[0]['due_date'] ?? $data['due_date'],
            'due_amount' => $totalAmount,
            'paid_amount' => 0,
            'paid_date' => null,
            'is_effective' => (bool) ($data['is_effective'] ?? true),
            'auto_register_payment_on_due_date' => (bool) ($data['auto_register_payment_on_due_date'] ?? false),
            'auto_payment_financial_account_id' => $data['auto_payment_financial_account_id'] ?? null,
            'paid' => false,
        ];
    }

    private function formatSequenceNumber(int $sequence): string
    {
        return str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    }

    private function recalculatePayableInstallment(AccountPayableInstallment $installment): void
    {
        $installment->loadMissing('payments', 'accountPayable');

        $paid = round((float) $installment->payments->sum(fn (AccountPayableInstallmentPayment $payment) => (float) $payment->amount), 2);
        $interest = round((float) $installment->payments->sum(fn (AccountPayableInstallmentPayment $payment) => (float) $payment->interest_amount), 2);
        $fine = round((float) $installment->payments->sum(fn (AccountPayableInstallmentPayment $payment) => (float) $payment->fine_amount), 2);
        $discount = round((float) $installment->payments->sum(fn (AccountPayableInstallmentPayment $payment) => (float) $payment->discount_amount), 2);
        $dueAmount = round((float) $installment->original_amount + $interest + $fine - $discount, 2);
        $balance = max(round($dueAmount - $paid, 2), 0);
        $status = $balance <= 0
            ? Status::PAID->value
            : ($paid > 0 ? Status::PARTIALLY_PAID->value : Status::PENDING->value);

        $installment->update([
            'interest_amount' => $interest,
            'fine_amount' => $fine,
            'discount_amount' => $discount,
            'due_amount' => $dueAmount,
            'paid_amount' => $paid,
            'balance_amount' => $balance,
            'paid_date' => $status === Status::PAID->value
                ? $installment->payments->max('payment_date')
                : null,
            'status' => $status,
        ]);
    }

    /**
     * @param array<string, mixed> $installmentData
     * @return array<string, mixed>
     */
    private function buildInstallmentRecordData(array $installmentData, AccountPayable $accountPayable): array
    {
        $amount = (float) ($installmentData['due_amount'] ?? 0);

        return [
            'account_payable_id' => $accountPayable->id,
            'company_id' => $accountPayable->company_id,
            'sequence_number' => $installmentData['sequence_number'],
            'status' => Status::PENDING->value,
            'due_date' => $installmentData['due_date'],
            'paid_date' => null,
            'original_amount' => $amount,
            'interest_amount' => 0,
            'fine_amount' => 0,
            'discount_amount' => 0,
            'due_amount' => $amount,
            'paid_amount' => 0,
            'balance_amount' => $amount,
            'bank_account_id' => $installmentData['bank_account_id'] ?? null,
            'financial_category_id' => $this->classificationService->resolveInstallmentCategoryId(
                $installmentData['financial_category_id'] ?? null,
                $accountPayable->company_id,
                'payable'
            ),
            'cost_center_id' => $installmentData['cost_center_id'] ?? null,
            'description' => $installmentData['description']
                ?? InstallmentDescription::fallbackForPayable($accountPayable, $installmentData['sequence_number'] ?? null),
            'notes' => $installmentData['notes'] ?? null,
        ];
    }

    public function update(AccountPayable $accountPayable, array $data, int $updatedBy): ?AccountPayable
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($accountPayable, $data, $updatedBy) {
                $audit = app(AuditRecorder::class);
                $before = $audit->snapshot($accountPayable);
                unset($data['paid'], $data['paid_amount'], $data['paid_date'], $data['status']);

                $action = new UpdateAccountPayableAction($updatedBy, $accountPayable);
                $updated = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'account_payable_id' => $accountPayable->id,
                        'message' => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors' => $action->getErrors(),
                        'data' => $data,
                        'user_id' => $updatedBy,
                    ]);

                    return null;
                }

                $syncAction = new SyncAccountPayableStatusFromInstallmentsAction($updated);
                $synced = $syncAction->execute();

                if ($syncAction->hasError() || $synced === null) {
                    $this->setError(
                        $syncAction->getMessage() ?: 'Falha ao sincronizar status da conta a pagar',
                        $syncAction->getErrors(),
                        422,
                        $syncAction->getErrorCode()
                    );

                    return null;
                }

                $synced->refresh();
                $audit->recordModelEvent(
                    $synced,
                    'account_payable.updated',
                    'Conta a pagar atualizada',
                    $before,
                    $audit->snapshot($synced),
                    $updatedBy,
                );

                $this->setSuccess('Conta a pagar atualizada com sucesso');

                Log::info('Conta a pagar atualizada com sucesso via service', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'account_payable_id' => $accountPayable->id,
                ]);

                return $synced;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar conta a pagar');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'account_payable_id' => $accountPayable->id,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
                'user_id' => $updatedBy,
            ]);

            return null;
        }
    }

    public function registerInstallmentPayment(
        AccountPayableInstallment $installment,
        float $amount,
        string $paymentDate,
        array $extra = []
    ): ?AccountPayableInstallmentPayment {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($installment, $amount, $paymentDate, $extra) {
                $audit = app(AuditRecorder::class);
                $userId = $extra['user_id'] ?? auth()->id();
                $installment->loadMissing('accountPayable');
                $before = $audit->snapshot($installment->accountPayable);
                $paymentAction = new RegisterAccountPayableInstallmentPaymentAction($installment);
                $payment = $paymentAction->execute([
                    'account_payable_installment_id' => $installment->id,
                    'company_id' => $installment->company_id,
                    'payment_date' => $paymentDate,
                    'amount' => $amount,
                    'interest_amount' => (float) ($extra['interest_amount'] ?? 0),
                    'fine_amount' => (float) ($extra['fine_amount'] ?? 0),
                    'discount_amount' => (float) ($extra['discount_amount'] ?? 0),
                    'bank_account_id' => $extra['bank_account_id'] ?? null,
                    'financial_account_id' => $extra['financial_account_id'] ?? null,
                    'description' => $extra['description']
                        ?? InstallmentDescription::forPayableInstallment($installment),
                    'notes' => $extra['notes'] ?? null,
                ]);

                if ($paymentAction->hasError() || $payment === null) {
                    $this->setError(
                        $paymentAction->getMessage(),
                        $paymentAction->getErrors(),
                        422,
                        $paymentAction->getErrorCode()
                    );

                    return null;
                }

                if ($installment->accountPayable->company_id !== $installment->company_id) {
                    throw ValidationException::withMessages([
                        'company_id' => ['Empresa da parcela divergente da conta a pagar.'],
                    ]);
                }

                $syncAction = new SyncAccountPayableStatusFromInstallmentsAction($installment->accountPayable);
                $synced = $syncAction->execute();

                if ($syncAction->hasError() || $synced === null) {
                    $this->setError(
                        $syncAction->getMessage() ?: 'Falha ao sincronizar status da conta a pagar',
                        $syncAction->getErrors(),
                        422,
                        $syncAction->getErrorCode()
                    );

                    return null;
                }

                if ($payment->financial_account_id) {
                    $movement = $this->cashMovementService->syncForPayablePayment($payment, $userId);

                    if ($this->cashMovementService->hasError() || $movement === null) {
                        $this->setError(
                            $this->cashMovementService->getMessage(),
                            $this->cashMovementService->getErrors(),
                            422,
                            $this->cashMovementService->getErrorCode()
                        );

                        return null;
                    }
                }

                $auditable = $installment->accountPayable->fresh();
                $audit->recordModelEvent(
                    $auditable,
                    'account_payable.payment_registered',
                    "Pagamento registrado para a parcela {$installment->sequence_number}",
                    $before,
                    $audit->snapshot($auditable),
                    $userId,
                    null,
                    [
                        'installment_id' => $installment->id,
                        'payment_id' => $payment->id,
                        'payment_amount' => (float) $payment->amount,
                    ],
                );

                $this->setSuccess('Pagamento da parcela registrado com sucesso.');

                return $payment;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors(), 422);
            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao registrar pagamento da parcela.');
            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'installment_id' => $installment->id,
                'payload' => [
                    'amount' => $amount,
                    'payment_date' => $paymentDate,
                    'extra' => $extra,
                ],
            ]);

            return null;
        }
    }

    public function updateInstallment(AccountPayableInstallment $installment, array $data): ?AccountPayableInstallment
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($installment, $data) {
                $installment->loadMissing('accountPayable');

                if ($installment->accountPayable->company_id !== $installment->company_id) {
                    throw ValidationException::withMessages([
                        'company_id' => ['Empresa da parcela divergente da conta a pagar.'],
                    ]);
                }

                $data['account_payable_id'] = $installment->account_payable_id;
                $data['company_id'] = $installment->company_id;

                $action = new UpdateAccountPayableInstallmentAction($installment);
                $updated = $action->execute($data);

                if ($action->hasError() || $updated === null) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    return null;
                }

                $this->recalculatePayableInstallment($updated->fresh());

                $syncAction = new SyncAccountPayableStatusFromInstallmentsAction($installment->accountPayable);
                $synced = $syncAction->execute();

                if ($syncAction->hasError() || $synced === null) {
                    $this->setError(
                        $syncAction->getMessage() ?: 'Falha ao sincronizar status da conta a pagar',
                        $syncAction->getErrors(),
                        422,
                        $syncAction->getErrorCode()
                    );

                    return null;
                }

                $this->setSuccess('Parcela atualizada com sucesso.');

                return $updated;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors(), 422);
            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar parcela.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'installment_id' => $installment->id,
                'payload' => $data,
            ]);

            return null;
        }
    }

    public function deleteInstallment(AccountPayableInstallment $installment): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($installment) {
                $installment->loadMissing('accountPayable');

                if ($installment->accountPayable->company_id !== $installment->company_id) {
                    throw ValidationException::withMessages([
                        'company_id' => ['Empresa da parcela divergente da conta a pagar.'],
                    ]);
                }

                $action = new DeleteAccountPayableInstallmentAction($installment);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    return false;
                }

                $syncAction = new SyncAccountPayableStatusFromInstallmentsAction($installment->accountPayable);
                $synced = $syncAction->execute();

                if ($syncAction->hasError() || $synced === null) {
                    $this->setError(
                        $syncAction->getMessage() ?: 'Falha ao sincronizar status da conta a pagar',
                        $syncAction->getErrors(),
                        422,
                        $syncAction->getErrorCode()
                    );

                    return false;
                }

                $this->setSuccess('Parcela excluída com sucesso.');

                return $result;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors(), 422);
            return false;
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir parcela.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'installment_id' => $installment->id,
            ]);

            return false;
        }
    }

    public function updateInstallmentPayment(
        AccountPayableInstallmentPayment $payment,
        array $data
    ): ?AccountPayableInstallmentPayment {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($payment, $data) {
                $userId = $data['user_id'] ?? auth()->id();
                $payment->loadMissing('installment.accountPayable');

                $validated = AccountPayableInstallmentValidator::validatePayment([
                    'account_payable_installment_id' => $payment->account_payable_installment_id,
                    'company_id' => $payment->company_id,
                    'payment_date' => $data['payment_date'] ?? $payment->payment_date?->toDateString(),
                    'amount' => $data['amount'] ?? $payment->amount,
                    'interest_amount' => $data['interest_amount'] ?? $payment->interest_amount,
                    'fine_amount' => $data['fine_amount'] ?? $payment->fine_amount,
                    'discount_amount' => $data['discount_amount'] ?? $payment->discount_amount,
                    'bank_account_id' => $data['bank_account_id'] ?? $payment->bank_account_id,
                    'financial_account_id' => $data['financial_account_id'] ?? $payment->financial_account_id,
                    'description' => $data['description'] ?? $payment->description,
                    'notes' => $data['notes'] ?? $payment->notes,
                ]);

                $payment->update($validated);

                $installment = $payment->installment->fresh();
                $this->recalculatePayableInstallment($installment);

                $syncAction = new SyncAccountPayableStatusFromInstallmentsAction($installment->accountPayable);
                $synced = $syncAction->execute();

                if ($syncAction->hasError() || $synced === null) {
                    $this->setError(
                        $syncAction->getMessage() ?: 'Falha ao sincronizar status da conta a pagar',
                        $syncAction->getErrors(),
                        422,
                        $syncAction->getErrorCode()
                    );

                    return null;
                }

                if ($payment->financial_account_id) {
                    $movement = $this->cashMovementService->syncForPayablePayment($payment->fresh(), $userId);

                    if ($this->cashMovementService->hasError() || $movement === null) {
                        $this->setError(
                            $this->cashMovementService->getMessage(),
                            $this->cashMovementService->getErrors(),
                            422,
                            $this->cashMovementService->getErrorCode()
                        );

                        return null;
                    }
                }

                $this->setSuccess('Pagamento atualizado com sucesso.');

                return $payment->fresh();
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados', $e->errors(), 422);
            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar pagamento.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payment_id' => $payment->id,
                'payload' => $data,
            ]);

            return null;
        }
    }

    public function deleteInstallmentPayment(AccountPayableInstallmentPayment $payment): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($payment) {
                $userId = auth()->id();
                $payment->loadMissing('installment.accountPayable');

                $reversal = $this->cashMovementService->reverseForPayablePayment($payment, $userId);

                if ($this->cashMovementService->hasError()) {
                    $this->setError(
                        $this->cashMovementService->getMessage(),
                        $this->cashMovementService->getErrors(),
                        422,
                        $this->cashMovementService->getErrorCode()
                    );

                    return false;
                }

                $installment = $payment->installment;
                $deleted = $payment->delete();

                if (! $deleted) {
                    $this->setError('Erro ao excluir pagamento.');
                    return false;
                }

                $this->recalculatePayableInstallment($installment->fresh());

                $syncAction = new SyncAccountPayableStatusFromInstallmentsAction($installment->accountPayable);
                $synced = $syncAction->execute();

                if ($syncAction->hasError() || $synced === null) {
                    $this->setError(
                        $syncAction->getMessage() ?: 'Falha ao sincronizar status da conta a pagar',
                        $syncAction->getErrors(),
                        422,
                        $syncAction->getErrorCode()
                    );

                    return false;
                }

                $this->setSuccess('Pagamento excluido com sucesso.');

                return true;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir pagamento.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payment_id' => $payment->id,
            ]);

            return false;
        }
    }

    public function delete(AccountPayable $accountPayable): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($accountPayable) {
                $audit = app(AuditRecorder::class);
                $before = $audit->snapshot($accountPayable);
                $action = new DeleteAccountPayableAction($accountPayable);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'account_payable_id' => $accountPayable->id,
                        'message' => $action->getMessage(),
                        'error_code' => $action->getErrorCode(),
                    ]);

                    return false;
                }

                $audit->recordModelEvent(
                    $accountPayable,
                    'account_payable.deleted',
                    'Conta a pagar excluida',
                    $before,
                    null,
                    auth()->id(),
                );

                $this->setSuccess('Conta a pagar excluída com sucesso');

                Log::info('Conta a pagar excluída com sucesso via service', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'account_payable_id' => $accountPayable->id,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir conta a pagar');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'account_payable_id' => $accountPayable->id,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
