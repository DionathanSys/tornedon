<?php

namespace App\Services\AccountReceivable;

use App\Enum\AccountReceivable\Status;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\AccountReceivableInstallmentPayment;
use App\Services\AccountReceivable\Actions\CreateAccountReceivableAction;
use App\Services\AccountReceivable\Actions\DeleteAccountReceivableAction;
use App\Services\AccountReceivable\Actions\Installment\CreateAccountReceivableInstallmentAction;
use App\Services\AccountReceivable\Actions\Installment\DeleteAccountReceivableInstallmentAction;
use App\Services\AccountReceivable\Actions\Installment\RegisterAccountReceivableInstallmentPaymentAction;
use App\Services\AccountReceivable\Actions\Installment\SyncAccountReceivableStatusFromInstallmentsAction;
use App\Services\AccountReceivable\Actions\Installment\UpdateAccountReceivableInstallmentAction;
use App\Services\AccountReceivable\Actions\UpdateAccountReceivableAction;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AccountReceivableService
{
    use HandlesServiceResponse;

    public function create(array $data, int $createdBy): ?AccountReceivable
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $installmentCount = max(1, (int) ($data['installment_count'] ?? 1));
                $scheduleConfig = $this->extractInstallmentScheduleConfig($data);
                unset($data['installment_count']);

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
                unset($data['paid'], $data['paid_amount'], $data['paid_date'], $data['status']);

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
                $installment->loadMissing('accountReceivable');
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
                $installment->loadMissing('accountReceivable');

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
                $installment->loadMissing('accountReceivable');

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

    public function delete(AccountReceivable $accountReceivable): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($accountReceivable) {
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
                'due_date' => $this->installmentDueDate($baseDate, $index, $scheduleConfig)->toDateString(),
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

    /**
     * @return array{mode: string, fixed_day: int|null}
     */
    private function extractInstallmentScheduleConfig(array &$data): array
    {
        $config = [
            'mode' => (string) ($data['installment_due_mode'] ?? 'interval_30_days'),
            'fixed_day' => isset($data['installment_fixed_day']) ? (int) $data['installment_fixed_day'] : null,
        ];

        unset($data['installment_due_mode'], $data['installment_fixed_day']);

        return $config;
    }

    private function installmentDueDate(Carbon $baseDate, int $index, array $scheduleConfig = []): CarbonInterface
    {
        if ($index === 0) {
            return $baseDate->copy();
        }

        $mode = $scheduleConfig['mode'] ?? 'interval_30_days';

        if ($mode === 'fixed_day_of_month') {
            $fixedDay = (int) ($scheduleConfig['fixed_day'] ?? $baseDate->day);
            $dueDate = $baseDate->copy()->addMonthsNoOverflow($index);

            return $dueDate->day(min($fixedDay, $dueDate->daysInMonth));
        }

        return $baseDate->copy()->addDays(30 * $index);
    }

    private function formatSequenceNumber(int $sequence): string
    {
        return str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
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
            'financial_category_id' => $installmentData['financial_category_id'] ?? null,
            'cost_center_id' => $installmentData['cost_center_id'] ?? null,
            'notes' => $installmentData['notes'] ?? $installmentData['description'] ?? null,
        ];
    }
}
