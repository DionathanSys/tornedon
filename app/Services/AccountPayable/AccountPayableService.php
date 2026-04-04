<?php

namespace App\Services\AccountPayable;

use App\Enum\AccountPayable\Status;
use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\AccountPayableInstallmentPayment;
use App\Services\AccountPayable\Actions\CreateAccountPayableAction;
use App\Services\AccountPayable\Actions\DeleteAccountPayableAction;
use App\Services\AccountPayable\Actions\UpdateAccountPayableAction;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AccountPayableService
{
    use HandlesServiceResponse;

    public function create(array $data, int $createdBy): ?AccountPayable
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $installmentCount = max(1, (int) ($data['installment_count'] ?? 1));
                $scheduleConfig = $this->extractInstallmentScheduleConfig($data);
                unset($data['installment_count']);

                $installments = $this->buildInstallmentsData($data, $installmentCount, $scheduleConfig);
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
                    AccountPayableInstallment::create([
                        ...$installmentData,
                        'account_payable_id' => $accountPayable->id,
                        'company_id' => $accountPayable->company_id,
                        'status' => Status::PENDING->value,
                        'original_amount' => $installmentData['due_amount'],
                        'interest_amount' => 0,
                        'fine_amount' => 0,
                        'discount_amount' => 0,
                        'paid_amount' => 0,
                        'balance_amount' => $installmentData['due_amount'],
                    ]);
                }

                $this->setSuccess('Conta a pagar criada com sucesso');

                Log::info('Conta a pagar criada com sucesso via service', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'account_payable_id' => $accountPayable->id,
                    'installments'       => $installmentCount,
                ]);

                return $accountPayable;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors(), 422);

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'data'       => $data,
                'user_id'    => $createdBy,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao criar conta a pagar');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $createdBy,
            ]);

            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
<<<<<<< HEAD
    private function buildInstallmentsData(array $data, int $installmentCount, array $scheduleConfig = []): array
=======
    private function buildInstallmentsData(array $data, int $installmentCount): array
>>>>>>> 7587eb8ace2fff4bbfba2fb003498fc630a78e0e
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
<<<<<<< HEAD
                'due_date' => $this->installmentDueDate($baseDate, $index, $scheduleConfig)->toDateString(),
=======
                'due_date' => $this->installmentDueDate($baseDate, $index)->toDateString(),
>>>>>>> 7587eb8ace2fff4bbfba2fb003498fc630a78e0e
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

<<<<<<< HEAD
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

=======
    private function installmentDueDate(Carbon $baseDate, int $index): CarbonInterface
    {
>>>>>>> 7587eb8ace2fff4bbfba2fb003498fc630a78e0e
        return $baseDate->copy()->addDays(30 * $index);
    }

    private function formatSequenceNumber(int $sequence): string
    {
        return str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    }

    public function update(AccountPayable $accountPayable, array $data, int $updatedBy): ?AccountPayable
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($accountPayable, $data, $updatedBy) {
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
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'account_payable_id' => $accountPayable->id,
                        'message'            => $this->getMessage(),
                        'error_code'         => $this->getErrorCode(),
                        'errors'             => $action->getErrors(),
                        'data'               => $data,
                        'user_id'            => $updatedBy,
                    ]);

                    return null;
                }

                $this->syncStatusFromInstallments($updated);
                $this->setSuccess('Conta a pagar atualizada com sucesso');

                Log::info('Conta a pagar atualizada com sucesso via service', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'account_payable_id' => $accountPayable->id,
                ]);

                return $updated;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar conta a pagar');

            Log::error($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'account_payable_id' => $accountPayable->id,
                'error_code'         => $this->getErrorCode(),
                'message'            => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
                'data'               => $data,
                'user_id'            => $updatedBy,
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
                if ($amount <= 0) {
                    throw ValidationException::withMessages([
                        'amount' => ['O valor do pagamento deve ser maior que zero.'],
                    ]);
                }

                $payment = AccountPayableInstallmentPayment::create([
                    'account_payable_installment_id' => $installment->id,
                    'company_id' => $installment->company_id,
                    'payment_date' => $paymentDate,
                    'amount' => $amount,
                    'interest_amount' => (float) ($extra['interest_amount'] ?? 0),
                    'fine_amount' => (float) ($extra['fine_amount'] ?? 0),
                    'discount_amount' => (float) ($extra['discount_amount'] ?? 0),
                    'bank_account_id' => $extra['bank_account_id'] ?? null,
                    'notes' => $extra['notes'] ?? null,
                ]);

                $totalPaid = round((float) $installment->payments()->sum('amount'), 2);
                $interest = round((float) $installment->payments()->sum('interest_amount'), 2);
                $fine = round((float) $installment->payments()->sum('fine_amount'), 2);
                $discount = round((float) $installment->payments()->sum('discount_amount'), 2);
                $dueAmount = round((float) $installment->original_amount + $interest + $fine - $discount, 2);
                $balance = round($dueAmount - $totalPaid, 2);

                $installment->update([
                    'interest_amount' => $interest,
                    'fine_amount' => $fine,
                    'discount_amount' => $discount,
                    'due_amount' => $dueAmount,
                    'paid_amount' => $totalPaid,
                    'balance_amount' => max($balance, 0),
                    'paid_date' => $balance <= 0 ? $paymentDate : null,
                    'status' => $balance <= 0
                        ? Status::PAID->value
                        : ($totalPaid > 0 ? Status::PARTIALLY_PAID->value : Status::PENDING->value),
                ]);

                $this->syncStatusFromInstallments($installment->accountPayable);
                $this->setSuccess('Pagamento da parcela registrado com sucesso.');

                return $payment;
            });
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors(), 422);
            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao registrar pagamento da parcela.');
            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'installment_id' => $installment->id,
            ]);

            return null;
        }
    }

    private function syncStatusFromInstallments(AccountPayable $accountPayable): void
    {
        $accountPayable->loadMissing('installments');

        $totalDue = round((float) $accountPayable->installments->sum('due_amount'), 2);
        $totalPaid = round((float) $accountPayable->installments->sum('paid_amount'), 2);
        $hasOverdue = $accountPayable->installments
            ->contains(fn (AccountPayableInstallment $installment) => $installment->balance_amount > 0 && $installment->due_date?->isPast());

        $status = match (true) {
            $totalPaid >= $totalDue && $totalDue > 0 => Status::PAID->value,
            $totalPaid > 0 => Status::PARTIALLY_PAID->value,
            $hasOverdue => Status::OVERDUE->value,
            default => Status::PENDING->value,
        };

        $accountPayable->update([
            'status' => $status,
            'paid' => $status === Status::PAID->value,
            'paid_amount' => $totalPaid,
            'paid_date' => $status === Status::PAID->value
                ? $accountPayable->installments->max('paid_date')
                : null,
            'due_amount' => $totalDue,
            'due_date' => $accountPayable->installments->min('due_date') ?? $accountPayable->due_date,
        ]);
    }

    public function delete(AccountPayable $accountPayable): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($accountPayable) {
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
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'account_payable_id' => $accountPayable->id,
                        'message'            => $action->getMessage(),
                        'error_code'         => $action->getErrorCode(),
                    ]);

                    return false;
                }

                $this->setSuccess('Conta a pagar excluída com sucesso');

                Log::info('Conta a pagar excluída com sucesso via service', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'account_payable_id' => $accountPayable->id,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir conta a pagar');

            Log::error($this->getMessage(), [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'account_payable_id' => $accountPayable->id,
                'error_code'         => $this->getErrorCode(),
                'message'            => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
