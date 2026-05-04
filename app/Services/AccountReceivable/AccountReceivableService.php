<?php

namespace App\Services\AccountReceivable;

use App\Enum\AccountReceivable\Status;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Models\CardPaymentProfile;
use App\Services\AccountReceivable\Actions\CreateAccountReceivableAction;
use App\Services\AccountReceivable\Actions\DeleteAccountReceivableAction;
use App\Services\AccountReceivable\Actions\Installment\CreateAccountReceivableInstallmentAction;
use App\Services\AccountReceivable\Actions\Installment\DeleteAccountReceivableInstallmentAction;
use App\Services\AccountReceivable\Actions\Installment\RegisterAccountReceivableInstallmentPaymentAction;
use App\Services\AccountReceivable\Actions\Installment\SyncAccountReceivableStatusFromInstallmentsAction;
use App\Services\AccountReceivable\Actions\Installment\UpdateAccountReceivableInstallmentAction;
use App\Services\AccountReceivable\Actions\UpdateAccountReceivableAction;
use App\Services\AccountReceivable\Validators\AccountReceivableInstallmentValidator;
use App\Services\Audit\AuditRecorder;
use App\Services\Financial\CashMovementService;
use App\Services\Financial\CardReceivableCalculatorService;
use App\Services\Financial\FinancialClassificationService;
use App\Support\Financial\InstallmentDescription;
use App\Support\Financial\InstallmentSchedule;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AccountReceivableService
{
    use HandlesServiceResponse;

    public function __construct(
        private readonly FinancialClassificationService $classificationService = new FinancialClassificationService(),
        private readonly CashMovementService $cashMovementService = new CashMovementService(),
        private readonly CardReceivableCalculatorService $cardReceivableCalculatorService = new CardReceivableCalculatorService(),
    ) {}

    public function create(array $data, int $createdBy): ?AccountReceivable
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $audit = app(AuditRecorder::class);
                $installmentCount = max(1, (int) ($data['installment_count'] ?? 1));
                $scheduleConfig = InstallmentSchedule::extractConfig($data);
                unset($data['installment_count']);

                $data = $this->applyCardRulesForCreate($data);

                $installments = $this->buildInstallmentsData($data, $installmentCount, $scheduleConfig);
                $action = new CreateAccountReceivableAction($createdBy);
                $headerData = $this->buildHeaderData($data, $installments);
                $accountReceivable = $action->execute($headerData);

                if ($action->hasError() || $accountReceivable === null) {
                    throw ValidationException::withMessages(
                        $action->getErrors() ?: ['conta_a_receber' => [$action->getMessage()]]
                    );
                }

                if (empty($installments)) {
                    throw new \RuntimeException('Nenhuma conta a receber foi gerada.');
                }

                foreach ($installments as $installmentData) {
                    $createInstallmentAction = new CreateAccountReceivableInstallmentAction();
                    $createdInstallment = $createInstallmentAction->execute(
                        $this->buildInstallmentRecordData($installmentData, $accountReceivable)
                    );

                    if ($createInstallmentAction->hasError() || $createdInstallment === null) {
                        throw ValidationException::withMessages(
                            $createInstallmentAction->getErrors() ?: ['parcela' => [$createInstallmentAction->getMessage()]]
                        );
                    }
                }

                $syncAction = new SyncAccountReceivableStatusFromInstallmentsAction($accountReceivable);
                $syncedAccountReceivable = $syncAction->execute();

                if ($syncAction->hasError() || $syncedAccountReceivable === null) {
                    throw ValidationException::withMessages([
                        'conta_a_receber' => [$syncAction->getMessage() ?: 'Falha ao sincronizar status da conta a receber.'],
                    ]);
                }

                $syncedAccountReceivable->refresh();
                $audit->recordModelEvent(
                    $syncedAccountReceivable,
                    'account_receivable.created',
                    'Conta a receber criada',
                    null,
                    $audit->snapshot($syncedAccountReceivable),
                    $createdBy,
                    null,
                    [
                        'installments_count' => $installmentCount,
                    ],
                );

                $this->setSuccess('Conta a receber criada com sucesso');

                Log::info('Conta a receber criada com sucesso via service', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'account_receivable_id' => $syncedAccountReceivable->id,
                    'installments' => $installmentCount,
                ]);

                return $syncedAccountReceivable;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados', $e->errors(), 422);

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
            $this->setError('Erro ao criar conta a receber');

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

    public function update(AccountReceivable $accountReceivable, array $data, int $updatedBy): ?AccountReceivable
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($accountReceivable, $data, $updatedBy) {
                $audit = app(AuditRecorder::class);
                $before = $audit->snapshot($accountReceivable);
                unset($data['paid'], $data['paid_amount'], $data['paid_date'], $data['status']);
                $data = $this->applyCardRulesForUpdate($accountReceivable, $data);

                $action = new UpdateAccountReceivableAction($updatedBy, $accountReceivable);
                $updated = $action->execute($data);

                if ($action->hasError() || $updated === null) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'account_receivable_id' => $accountReceivable->id,
                        'message' => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors' => $action->getErrors(),
                        'data' => $data,
                        'user_id' => $updatedBy,
                    ]);

                    return null;
                }

                $syncAction = new SyncAccountReceivableStatusFromInstallmentsAction($updated);
                $synced = $syncAction->execute();

                if ($syncAction->hasError() || $synced === null) {
                    $this->setError(
                        $syncAction->getMessage() ?: 'Falha ao sincronizar status da conta a receber',
                        $syncAction->getErrors(),
                        422,
                        $syncAction->getErrorCode()
                    );

                    return null;
                }

                $synced->refresh();
                $audit->recordModelEvent(
                    $synced,
                    'account_receivable.updated',
                    'Conta a receber atualizada',
                    $before,
                    $audit->snapshot($synced),
                    $updatedBy,
                );

                $this->setSuccess('Conta a receber atualizada com sucesso');

                Log::info('Conta a receber atualizada com sucesso via service', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'account_receivable_id' => $accountReceivable->id,
                ]);

                return $synced;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar conta a receber');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'account_receivable_id' => $accountReceivable->id,
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
        AccountReceivableInstallment $installment,
        float $amount,
        string $paymentDate,
        array $extra = []
    ): ?AccountReceivableInstallmentPayment {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($installment, $amount, $paymentDate, $extra) {
                $audit = app(AuditRecorder::class);
                $userId = $extra['user_id'] ?? auth()->id();
                $installment->loadMissing('accountReceivable');
                $before = $audit->snapshot($installment->accountReceivable);
                $paymentAction = new RegisterAccountReceivableInstallmentPaymentAction($installment);
                $payment = $paymentAction->execute([
                    'account_receivable_installment_id' => $installment->id,
                    'company_id' => $installment->company_id,
                    'payment_date' => $paymentDate,
                    'amount' => $amount,
                    'interest_amount' => (float) ($extra['interest_amount'] ?? 0),
                    'fine_amount' => (float) ($extra['fine_amount'] ?? 0),
                    'discount_amount' => (float) ($extra['discount_amount'] ?? 0),
                    'bank_account_id' => $extra['bank_account_id'] ?? null,
                    'financial_account_id' => $extra['financial_account_id'] ?? null,
                    'description' => $extra['description']
                        ?? InstallmentDescription::forReceivableInstallment($installment),
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

                if ($installment->accountReceivable->company_id !== $installment->company_id) {
                    throw ValidationException::withMessages([
                        'company_id' => ['Empresa da parcela divergente da conta a receber.'],
                    ]);
                }

                $syncAction = new SyncAccountReceivableStatusFromInstallmentsAction($installment->accountReceivable);
                $synced = $syncAction->execute();

                if ($syncAction->hasError() || $synced === null) {
                    $this->setError(
                        $syncAction->getMessage() ?: 'Falha ao sincronizar status da conta a receber',
                        $syncAction->getErrors(),
                        422,
                        $syncAction->getErrorCode()
                    );

                    return null;
                }

                if ($payment->financial_account_id) {
                    $movement = $this->cashMovementService->syncForReceivablePayment($payment, $userId);

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

                $auditable = $installment->accountReceivable->fresh();
                $audit->recordModelEvent(
                    $auditable,
                    'account_receivable.payment_registered',
                    "Recebimento registrado para a parcela {$installment->sequence_number}",
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

                $this->setSuccess('Recebimento da parcela registrado com sucesso.');

                return $payment;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados', $e->errors(), 422);
            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao registrar recebimento da parcela.');

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

    public function updateInstallment(AccountReceivableInstallment $installment, array $data): ?AccountReceivableInstallment
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($installment, $data) {
                $audit = app(AuditRecorder::class);
                $userId = auth()->id();
                $installment->loadMissing('accountReceivable');
                $before = $audit->snapshot($installment);

                if ($installment->accountReceivable->company_id !== $installment->company_id) {
                    throw ValidationException::withMessages([
                        'company_id' => ['Empresa da parcela divergente da conta a receber.'],
                    ]);
                }

                $data['account_receivable_id'] = $installment->account_receivable_id;
                $data['company_id'] = $installment->company_id;

                $action = new UpdateAccountReceivableInstallmentAction($installment);
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

                $updated = $updated->fresh();
                $this->recalculateReceivableInstallment($updated);

                $syncAction = new SyncAccountReceivableStatusFromInstallmentsAction($installment->accountReceivable);
                $synced = $syncAction->execute();

                if ($syncAction->hasError() || $synced === null) {
                    $this->setError(
                        $syncAction->getMessage() ?: 'Falha ao sincronizar status da conta a receber',
                        $syncAction->getErrors(),
                        422,
                        $syncAction->getErrorCode()
                    );

                    return null;
                }

                $audit->recordModelEvent(
                    $synced->fresh(),
                    'account_receivable.installment_updated',
                    "Parcela {$updated->sequence_number} da conta a receber atualizada",
                    $before,
                    $audit->snapshot($updated),
                    $userId,
                    null,
                    [
                        'installment_id' => $updated->id,
                        'sequence_number' => $updated->sequence_number,
                    ],
                );

                $this->setSuccess('Parcela atualizada com sucesso.');

                return $updated;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados', $e->errors(), 422);
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

    public function deleteInstallment(AccountReceivableInstallment $installment): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($installment) {
                $audit = app(AuditRecorder::class);
                $userId = auth()->id();
                $installment->loadMissing('accountReceivable');
                $before = $audit->snapshot($installment);
                $installmentId = $installment->id;
                $sequenceNumber = $installment->sequence_number;

                if ($installment->accountReceivable->company_id !== $installment->company_id) {
                    throw ValidationException::withMessages([
                        'company_id' => ['Empresa da parcela divergente da conta a receber.'],
                    ]);
                }

                $action = new DeleteAccountReceivableInstallmentAction($installment);
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

                $syncAction = new SyncAccountReceivableStatusFromInstallmentsAction($installment->accountReceivable);
                $synced = $syncAction->execute();

                if ($syncAction->hasError() || $synced === null) {
                    $this->setError(
                        $syncAction->getMessage() ?: 'Falha ao sincronizar status da conta a receber',
                        $syncAction->getErrors(),
                        422,
                        $syncAction->getErrorCode()
                    );

                    return false;
                }

                $audit->recordModelEvent(
                    $synced->fresh(),
                    'account_receivable.installment_deleted',
                    "Parcela {$sequenceNumber} da conta a receber excluída",
                    $before,
                    null,
                    $userId,
                    null,
                    [
                        'installment_id' => $installmentId,
                        'sequence_number' => $sequenceNumber,
                    ],
                );

                $this->setSuccess('Parcela excluida com sucesso.');

                return $result;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados', $e->errors(), 422);
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
        AccountReceivableInstallmentPayment $payment,
        array $data
    ): ?AccountReceivableInstallmentPayment {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($payment, $data) {
                $userId = $data['user_id'] ?? auth()->id();
                $payment->loadMissing('installment.accountReceivable');

                $validated = AccountReceivableInstallmentValidator::validatePayment([
                    'account_receivable_installment_id' => $payment->account_receivable_installment_id,
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
                $this->recalculateReceivableInstallment($installment);

                $syncAction = new SyncAccountReceivableStatusFromInstallmentsAction($installment->accountReceivable);
                $synced = $syncAction->execute();

                if ($syncAction->hasError() || $synced === null) {
                    $this->setError(
                        $syncAction->getMessage() ?: 'Falha ao sincronizar status da conta a receber',
                        $syncAction->getErrors(),
                        422,
                        $syncAction->getErrorCode()
                    );

                    return null;
                }

                if ($payment->financial_account_id) {
                    $movement = $this->cashMovementService->syncForReceivablePayment($payment->fresh(), $userId);

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

                $this->setSuccess('Recebimento atualizado com sucesso.');

                return $payment->fresh();
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validacao dos dados', $e->errors(), 422);
            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar recebimento.');

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

    public function deleteInstallmentPayment(AccountReceivableInstallmentPayment $payment): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($payment) {
                $userId = auth()->id();
                $payment->loadMissing('installment.accountReceivable');

                $reversal = $this->cashMovementService->reverseForReceivablePayment($payment, $userId);

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
                    $this->setError('Erro ao excluir recebimento.');
                    return false;
                }

                $this->recalculateReceivableInstallment($installment->fresh());

                $syncAction = new SyncAccountReceivableStatusFromInstallmentsAction($installment->accountReceivable);
                $synced = $syncAction->execute();

                if ($syncAction->hasError() || $synced === null) {
                    $this->setError(
                        $syncAction->getMessage() ?: 'Falha ao sincronizar status da conta a receber',
                        $syncAction->getErrors(),
                        422,
                        $syncAction->getErrorCode()
                    );

                    return false;
                }

                $this->setSuccess('Recebimento excluido com sucesso.');

                return true;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir recebimento.');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payment_id' => $payment->id,
            ]);

            return false;
        }
    }

    public function delete(AccountReceivable $accountReceivable): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($accountReceivable) {
                $audit = app(AuditRecorder::class);
                $before = $audit->snapshot($accountReceivable);
                $action = new DeleteAccountReceivableAction($accountReceivable);
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
                        'account_receivable_id' => $accountReceivable->id,
                        'message' => $action->getMessage(),
                        'error_code' => $action->getErrorCode(),
                    ]);

                    return false;
                }

                $audit->recordModelEvent(
                    $accountReceivable,
                    'account_receivable.deleted',
                    'Conta a receber excluida',
                    $before,
                    null,
                    auth()->id(),
                );

                $this->setSuccess('Conta a receber excluida com sucesso');

                Log::info('Conta a receber excluida com sucesso via service', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'account_receivable_id' => $accountReceivable->id,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir conta a receber');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'account_receivable_id' => $accountReceivable->id,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildInstallmentsData(array $data, int $installmentCount, array $scheduleConfig = []): array
    {
        if ($installmentCount === 1) {
            $data['sequence_number'] = $this->formatSequenceNumber(1);
            return [$data];
        }

        $baseDate = Carbon::parse($data['due_date']);
        $totalInCents = (int) round(((float) $data['due_amount']) * 100);
        $baseInstallment = intdiv($totalInCents, $installmentCount);
        $remainder = $totalInCents - ($baseInstallment * $installmentCount);
        $installments = [];

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
            'paid' => false,
        ];
    }

    private function formatSequenceNumber(int $sequence): string
    {
        return str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    }

    private function recalculateReceivableInstallment(AccountReceivableInstallment $installment): void
    {
        $installment->loadMissing('payments', 'accountReceivable');

        $received = round((float) $installment->payments->sum(fn (AccountReceivableInstallmentPayment $payment) => (float) $payment->amount), 2);
        $interest = round((float) $installment->payments->sum(fn (AccountReceivableInstallmentPayment $payment) => (float) $payment->interest_amount), 2);
        $fine = round((float) $installment->payments->sum(fn (AccountReceivableInstallmentPayment $payment) => (float) $payment->fine_amount), 2);
        $discount = round((float) $installment->payments->sum(fn (AccountReceivableInstallmentPayment $payment) => (float) $payment->discount_amount), 2);
        $dueAmount = round((float) $installment->original_amount + $interest + $fine - $discount, 2);
        $balance = max(round($dueAmount - $received, 2), 0);
        $status = $balance <= 0
            ? Status::RECEIVED->value
            : ($received > 0 ? Status::PARTIALLY_RECEIVED->value : Status::PENDING->value);

        $installment->update([
            'interest_amount' => $interest,
            'fine_amount' => $fine,
            'discount_amount' => $discount,
            'due_amount' => $dueAmount,
            'received_amount' => $received,
            'balance_amount' => $balance,
            'received_date' => $status === Status::RECEIVED->value
                ? $installment->payments->max('payment_date')
                : null,
            'status' => $status,
        ]);
    }

    /**
     * @param array<string, mixed> $installmentData
     * @return array<string, mixed>
     */
    private function buildInstallmentRecordData(array $installmentData, AccountReceivable $accountReceivable): array
    {
        $amount = (float) ($installmentData['due_amount'] ?? 0);

        return [
            'account_receivable_id' => $accountReceivable->id,
            'company_id' => $accountReceivable->company_id,
            'sequence_number' => $installmentData['sequence_number'],
            'status' => Status::PENDING->value,
            'due_date' => $installmentData['due_date'],
            'received_date' => null,
            'original_amount' => $amount,
            'interest_amount' => 0,
            'fine_amount' => 0,
            'discount_amount' => 0,
            'due_amount' => $amount,
            'received_amount' => 0,
            'balance_amount' => $amount,
            'bank_account_id' => $installmentData['bank_account_id'] ?? null,
            'financial_category_id' => $this->classificationService->resolveInstallmentCategoryId(
                $installmentData['financial_category_id'] ?? null,
                $accountReceivable->company_id,
                'receivable'
            ),
            'cost_center_id' => $installmentData['cost_center_id'] ?? null,
            'description' => $installmentData['description']
                ?? InstallmentDescription::fallbackForReceivable($accountReceivable, $installmentData['sequence_number'] ?? null),
            'notes' => $installmentData['notes'] ?? $installmentData['description'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyCardRulesForCreate(array $data): array
    {
        $paymentMethod = (string) ($data['payment_method'] ?? '');
        $dueAmount = round((float) ($data['due_amount'] ?? 0), 2);

        if ($paymentMethod !== PaymentMethod::CREDIT_CARD->value) {
            return [
                ...$data,
                'card_payment_profile_id' => null,
                'gross_amount' => $dueAmount,
                'card_fee_percent_snapshot' => null,
                'card_fee_fixed_snapshot' => null,
                'card_fee_amount' => 0,
                'net_amount' => $dueAmount,
                'payment_date' => $data['payment_date'] ?? null,
                'settlement_days_snapshot' => null,
                'expected_settlement_date' => null,
                'card_rule_snapshot' => null,
            ];
        }

        $profile = $this->resolveCardProfile(
            (int) ($data['company_id'] ?? 0),
            (int) ($data['card_payment_profile_id'] ?? 0)
        );

        $paymentDate = (string) ($data['payment_date'] ?? '');

        if ($paymentDate === '') {
            throw ValidationException::withMessages([
                'payment_date' => ['A data do pagamento e obrigatoria para recebimentos em cartao de credito.'],
            ]);
        }

        $calculation = $this->cardReceivableCalculatorService->calculateFromProfile(
            $profile,
            (float) ($data['gross_amount'] ?? $dueAmount),
            $paymentDate,
        );

        return [
            ...$data,
            'due_amount' => $calculation->grossAmount,
            'gross_amount' => $calculation->grossAmount,
            'card_fee_percent_snapshot' => $calculation->feePercent,
            'card_fee_fixed_snapshot' => $calculation->feeFixed,
            'card_fee_amount' => $calculation->feeAmount,
            'net_amount' => $calculation->netAmount,
            'settlement_days_snapshot' => $calculation->settlementDays,
            'expected_settlement_date' => $calculation->expectedSettlementDate,
            'card_rule_snapshot' => $calculation->snapshot,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyCardRulesForUpdate(AccountReceivable $accountReceivable, array $data): array
    {
        $currentMethod = $accountReceivable->payment_method?->value;
        $nextMethod = (string) ($data['payment_method'] ?? $currentMethod ?? '');

        if ($nextMethod !== PaymentMethod::CREDIT_CARD->value) {
            $gross = round((float) ($data['due_amount'] ?? $accountReceivable->due_amount), 2);

            return [
                ...$data,
                'card_payment_profile_id' => null,
                'gross_amount' => $gross,
                'card_fee_percent_snapshot' => null,
                'card_fee_fixed_snapshot' => null,
                'card_fee_amount' => 0,
                'net_amount' => $gross,
                'settlement_days_snapshot' => null,
                'expected_settlement_date' => null,
                'card_rule_snapshot' => null,
            ];
        }

        $profileId = (int) ($data['card_payment_profile_id'] ?? $accountReceivable->card_payment_profile_id ?? 0);
        $profile = $this->resolveCardProfile((int) $accountReceivable->company_id, $profileId);

        $paymentDate = (string) ($data['payment_date'] ?? $accountReceivable->payment_date?->toDateString() ?? '');

        if ($paymentDate === '') {
            throw ValidationException::withMessages([
                'payment_date' => ['A data do pagamento e obrigatoria para recebimentos em cartao de credito.'],
            ]);
        }

        $grossAmount = (float) ($data['gross_amount'] ?? $data['due_amount'] ?? $accountReceivable->gross_amount ?? $accountReceivable->due_amount);
        $calculation = $this->cardReceivableCalculatorService->calculateFromProfile($profile, $grossAmount, $paymentDate);

        return [
            ...$data,
            'card_payment_profile_id' => $profile->id,
            'payment_date' => $paymentDate,
            'due_amount' => $calculation->grossAmount,
            'gross_amount' => $calculation->grossAmount,
            'card_fee_percent_snapshot' => $calculation->feePercent,
            'card_fee_fixed_snapshot' => $calculation->feeFixed,
            'card_fee_amount' => $calculation->feeAmount,
            'net_amount' => $calculation->netAmount,
            'settlement_days_snapshot' => $calculation->settlementDays,
            'expected_settlement_date' => $calculation->expectedSettlementDate,
            'card_rule_snapshot' => $calculation->snapshot,
        ];
    }

    private function resolveCardProfile(int $companyId, int $profileId): CardPaymentProfile
    {
        if ($companyId <= 0 || $profileId <= 0) {
            throw ValidationException::withMessages([
                'card_payment_profile_id' => ['Perfil de cartao invalido para o recebimento em cartao.'],
            ]);
        }

        $profile = CardPaymentProfile::query()
            ->where('company_id', $companyId)
            ->find($profileId);

        if (! $profile) {
            throw ValidationException::withMessages([
                'card_payment_profile_id' => ['Perfil de cartao nao encontrado para a empresa informada.'],
            ]);
        }

        if (! $profile->active) {
            throw ValidationException::withMessages([
                'card_payment_profile_id' => ['O perfil de cartao selecionado esta inativo.'],
            ]);
        }

        return $profile;
    }
}
