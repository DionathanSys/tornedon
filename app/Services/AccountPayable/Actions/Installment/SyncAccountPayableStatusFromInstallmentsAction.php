<?php

namespace App\Services\AccountPayable\Actions\Installment;

use App\Enum\AccountPayable\Status;
use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class SyncAccountPayableStatusFromInstallmentsAction
{
    use HandlesActionResponse;

    public function __construct(private readonly AccountPayable $accountPayable) {}

    public function execute(): ?AccountPayable
    {
        try {
            Log::debug('Iniciando sincronização de status da conta a pagar', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'account_payable_id' => $this->accountPayable->id,
                'company_id' => $this->accountPayable->company_id,
            ]);

            $this->accountPayable->loadMissing('installments');

            $totalDue = round((float) $this->accountPayable->installments->sum('due_amount'), 2);
            $totalPaid = round((float) $this->accountPayable->installments->sum('paid_amount'), 2);
            $hasOverdue = $this->accountPayable->installments
                ->contains(fn (AccountPayableInstallment $installment) => $installment->balance_amount > 0 && $installment->due_date?->isPast());

            $status = match (true) {
                $totalPaid >= $totalDue && $totalDue > 0 => Status::PAID->value,
                $totalPaid > 0 => Status::PARTIALLY_PAID->value,
                $hasOverdue => Status::OVERDUE->value,
                default => Status::PENDING->value,
            };

            $this->accountPayable->update([
                'status' => $status,
                'paid' => $status === Status::PAID->value,
                'paid_amount' => $totalPaid,
                'paid_date' => $status === Status::PAID->value
                    ? $this->accountPayable->installments->max('paid_date')
                    : null,
                'due_amount' => $totalDue,
                'due_date' => $this->accountPayable->installments->min('due_date') ?? $this->accountPayable->due_date,
            ]);

            $this->setSuccess();

            Log::info('Status da conta a pagar sincronizado com sucesso', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'account_payable_id' => $this->accountPayable->id,
                'status' => $status,
                'paid_amount' => $totalPaid,
                'due_amount' => $totalDue,
            ]);

            return $this->accountPayable->fresh();
        } catch (QueryException $e) {
            $this->setError('Erro ao sincronizar status da conta a pagar no banco de dados');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'account_payable_id' => $this->accountPayable->id,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao sincronizar status da conta a pagar');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'account_payable_id' => $this->accountPayable->id,
            ]);

            return null;
        }
    }
}
